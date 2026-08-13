<?php
/**
 * Message processing service
 * Handles conversation loop, tool execution, and Claude API interaction
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Message Processor class.
 *
 * Processes messages and handles AI tool calls.
 *
 * @since 1.0.0
 */
class Abilities_Bridge_Message_Processor {

	/**
	 * MCP Orchestrator instance
	 *
	 * @var Abilities_Bridge_MCP_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Result details retained for the legacy synchronous wrapper.
	 *
	 * @var array
	 */
	private $last_run_result = array();

	/**
	 * Most recently persisted initial user message.
	 *
	 * @var int
	 */
	private $initial_user_message_id = 0;

	/**
	 * Plan-mode snapshot for the legacy synchronous wrapper.
	 *
	 * @var bool
	 */
	private $initial_plan_mode = false;

	/**
	 * Constructor
	 *
	 * @param Abilities_Bridge_MCP_Orchestrator|null $orchestrator Optional orchestrator instance.
	 */
	public function __construct( $orchestrator = null ) {
		$this->orchestrator = $orchestrator ?? new Abilities_Bridge_MCP_Orchestrator();
	}

	/**
	 * Send message to Claude and handle tool use loop
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation instance.
	 * @param string                        $user_message User's message.
	 * @param bool                          $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @param array                         $image_blocks Stored image attachment blocks.
	 * @return array Result with success status and response/error
	 */
	public function send_and_process( $conversation, $user_message, $plan_mode = false, $image_blocks = array() ) {
		$message_id = $this->begin( $conversation, $user_message, $plan_mode, $image_blocks );
		if ( is_wp_error( $message_id ) ) {
			return array(
				'success'    => false,
				'error'      => self::get_user_friendly_error( $message_id ),
				'error_data' => $message_id->get_error_data(),
			);
		}

		$legacy_timeout = static function () {
			return 300;
		};
		add_filter( 'abilities_bridge_ai_request_timeout', $legacy_timeout, 999 );
		try {
			$result = $this->run_loop( $conversation, null, '' );
		} finally {
			remove_filter( 'abilities_bridge_ai_request_timeout', $legacy_timeout, 999 );
		}
		if ( is_wp_error( $result ) ) {
			return array(
				'success'    => false,
				'error'      => self::get_user_friendly_error( $result ),
				'error_data' => $result->get_error_data(),
			);
		}

		return array_merge(
			array( 'success' => true ),
			$this->last_run_result
		);
	}

	/**
	 * Persist the initial user turn without starting a provider request.
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation instance.
	 * @param string                        $user_message User text.
	 * @param bool                          $plan_mode Plan mode (pinned by the job row).
	 * @param array                         $image_blocks Stored image blocks.
	 * @param int                           $job_id Owning durable job.
	 * @return int|WP_Error Message ID or error.
	 */
	public function begin( $conversation, $user_message, $plan_mode = false, $image_blocks = array(), $job_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature pins enqueue context.
		$user_content = class_exists( 'Abilities_Bridge_Attachments' )
			? Abilities_Bridge_Attachments::build_user_content( $user_message, $image_blocks )
			: $user_message;

		$message_id = $conversation->add_user_message( $user_content, (int) $job_id );
		if ( is_wp_error( $message_id ) ) {
			return $message_id;
		}

		$this->initial_user_message_id = (int) $message_id;
		$this->initial_plan_mode       = (bool) $plan_mode;

		return (int) $message_id;
	}

	/**
	 * Run or resume the provider/tool loop.
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation instance.
	 * @param object|null                   $job Durable job, or null for legacy synchronous operation.
	 * @param string                        $lock Worker lock token.
	 * @return true|WP_Error True on success, otherwise a single error contract.
	 */
	public function run_loop( $conversation, $job = null, $lock = '' ) {
		$background      = is_object( $job );
		$conversation_id = (int) $conversation->get_id();
		$context         = $this->resolve_provider_context( $conversation_id, $job );
		$provider        = $context['provider'];
		$model           = $context['model'];
		$previous_id     = $context['previous_response_id'];
		$ai_client       = Abilities_Bridge_AI_Provider::create_client( $provider );
		$tools           = Abilities_Bridge_AI_Provider::get_tool_definitions();
		$started_at      = time();
		$rounds          = $background ? (int) $job->rounds_completed : 0;
		$max_rounds      = 10;
		$final_response  = '';
		$tool_usage      = array();
		$last_results    = array();
		$awaiting_text   = false;
		$received_text   = false;
		$finished        = false;
		$user_message_id = $background ? (int) $job->user_message_id : $this->initial_user_message_id;
		$plan_mode       = $background ? (bool) $job->plan_mode : $this->initial_plan_mode;

		if ( $background && in_array( $job->phase, array( 'tools_pending', 'tool_inflight' ), true ) ) {
			$tool_result = $this->resume_checkpointed_tools( $conversation, $job, $lock, $tool_usage, $last_results );
			if ( is_wp_error( $tool_result ) ) {
				return $tool_result;
			}
			$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
		}

		while ( $rounds < $max_rounds ) {
			if ( ! $background && time() - $started_at > 300 ) {
				return new WP_Error( 'conversation_timeout', __( 'The request exceeded the synchronous time limit.', 'abilities-bridge' ) );
			}

			if ( $background ) {
				$guard = $this->guard_worker( $job->id, $lock );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}

				$request_timeout = (int) apply_filters( 'abilities_bridge_ai_request_timeout', 300 );
				if ( ! Abilities_Bridge_Chat_Jobs::set_phase( $job->id, $lock, 'provider_inflight', $request_timeout + 90 ) ) {
					return new WP_Error( 'job_lock_lost', __( 'The chat worker lost its execution lock.', 'abilities-bridge' ) );
				}
				$guard = $this->guard_worker( $job->id, $lock );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				Abilities_Bridge_Chat_Jobs::touch_activity( $job->id, $lock, __( 'Waiting for the AI provider', 'abilities-bridge' ) );
				do_action( 'abilities_bridge_chat_job_checkpoint', 'provider_inflight', (int) $job->id, 0 );
			}

			if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider && method_exists( $ai_client, 'set_previous_response_id' ) ) {
				$ai_client->set_previous_response_id( $previous_id );
			}

			$response = $this->send_with_billing_safe_retries(
				$ai_client,
				$conversation->get_messages_for_api( array( 'hydrate_image_message_id' => $user_message_id ) ),
				$tools,
				$model,
				$provider,
				$background ? $job : null,
				$lock
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( $background ) {
				do_action( 'abilities_bridge_chat_job_checkpoint', 'provider_returned', (int) $job->id, 0 );
				$guard = $this->guard_worker( $job->id, $lock );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
			}

			$content_blocks = isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array();
			$text_responses = array();
			$tool_uses      = array();
			foreach ( $content_blocks as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$text_responses[] = $block['text'];
				} elseif ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
					$tool_uses[] = $block;
				}
			}
			$has_tools      = ! empty( $tool_uses ) && 'tool_use' === ( $response['stop_reason'] ?? '' );
			$intended_round = $rounds + 1;
			$message_id     = $conversation->add_assistant_message(
				$content_blocks,
				$background ? (int) $job->id : 0,
				$background ? $intended_round : 0
			);
			if ( is_wp_error( $message_id ) ) {
				return new WP_Error(
					'ai_generation_ambiguous',
					__( 'The AI answered, but the reply could not be saved safely. It was not submitted again.', 'abilities-bridge' ),
					array( 'provider' => $provider )
				);
			}
			if ( $background && ! Abilities_Bridge_Chat_Jobs::worker_may_continue( $job->id, $lock ) ) {
				Abilities_Bridge_Chat_Jobs::reconcile_terminal_context( $job->id );
				Abilities_Bridge_Chat_Jobs::mark_cancelled( $job->id, $lock );
				return new WP_Error( 'job_cancelled', __( 'The chat job was stopped before its reply could be published.', 'abilities-bridge' ) );
			}

			if ( $background && $has_tools ) {
				$seeded = Abilities_Bridge_Chat_Job_Steps::seed( $job->id, $intended_round, $tool_uses );
				if ( is_wp_error( $seeded ) ) {
					Abilities_Bridge_Chat_Jobs::reconcile_terminal_context( $job->id );
					return $seeded;
				}
			}

			if ( $background ) {
				$new_rounds = Abilities_Bridge_Chat_Jobs::checkpoint_provider_round( $job->id, $lock, $has_tools ? 'tools_pending' : 'completed' );
				if ( false === $new_rounds ) {
					Abilities_Bridge_Chat_Jobs::reconcile_terminal_context( $job->id );
					if ( Abilities_Bridge_Chat_Jobs::worker_may_continue( $job->id, $lock ) ) {
						return new WP_Error( 'provider_checkpoint_failed', __( 'The AI replied, but its durable round checkpoint could not be finalized.', 'abilities-bridge' ) );
					}
					return new WP_Error( 'job_cancelled', __( 'The chat job was stopped before its reply could be finalized.', 'abilities-bridge' ) );
				}
				$rounds = $new_rounds;
			} else {
				$rounds = $intended_round;
			}

			if ( ! empty( $response['response_id'] ) && Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider ) {
				if ( $background && $has_tools && ! Abilities_Bridge_Chat_Jobs::worker_may_continue( $job->id, $lock ) ) {
					Abilities_Bridge_Chat_Jobs::reconcile_terminal_context( $job->id );
					return new WP_Error( 'job_cancelled', __( 'The chat job was stopped.', 'abilities-bridge' ) );
				}
				$previous_id = sanitize_text_field( $response['response_id'] );
				Abilities_Bridge_Database::update_last_openai_response_id( $conversation_id, $previous_id );
				if ( $background ) {
					Abilities_Bridge_Chat_Jobs::store_provider_response_id( $job->id, $lock, $previous_id );
				}
			}

			if ( ! empty( $text_responses ) ) {
				$final_response .= implode( "\n\n", $text_responses ) . "\n\n";
				$received_text   = $awaiting_text || $received_text;
			}

			if ( ! $has_tools ) {
				$finished = true;
				break;
			}

			if ( $background ) {
				$job         = Abilities_Bridge_Chat_Jobs::get( $job->id );
				$tool_result = $this->resume_checkpointed_tools( $conversation, $job, $lock, $tool_usage, $last_results );
			} else {
				$tool_result = $this->execute_synchronous_tools( $conversation, $tool_uses, $plan_mode, $conversation_id, $tool_usage, $last_results );
			}

			if ( is_wp_error( $tool_result ) ) {
				return $tool_result;
			}
			$awaiting_text = true;
		}

		if ( ! $finished && $rounds >= $max_rounds ) {
			return new WP_Error( 'max_rounds_reached', __( 'The AI reached the maximum number of tool rounds for one request.', 'abilities-bridge' ) );
		}

		$final_response = trim( $final_response );
		if ( ! empty( $tool_usage ) && ( '' === $final_response || ! $received_text ) ) {
			$final_response = self::build_tool_result_fallback_response( $tool_usage, $last_results );
		}

		$this->last_run_result = array(
			'response'   => $final_response,
			'iterations' => $rounds,
			'tool_usage' => $tool_usage,
		);

		Abilities_Bridge_Logger::log_tool_progress( $conversation_id, 'response', 'completed', '✅ Processing complete, preparing response...' );

		return true;
	}

	/**
	 * Resolve the provider/model snapshot and continuation pointer.
	 *
	 * @param int         $conversation_id Conversation ID.
	 * @param object|null $job Durable job.
	 * @return array
	 */
	private function resolve_provider_context( $conversation_id, $job ) {
		$provider             = is_object( $job ) && ! empty( $job->provider ) ? $job->provider : Abilities_Bridge_AI_Provider::get_current_provider();
		$model                = is_object( $job ) && ! empty( $job->model ) ? $job->model : '';
		$previous_response_id = '';
		$data                 = Abilities_Bridge_Database::get_conversation( $conversation_id, get_current_user_id() );

		if ( $data ) {
			if ( ! is_object( $job ) ) {
				if ( ! empty( $data->provider ) ) {
					$provider = $data->provider;
				} elseif ( ! empty( $data->model ) ) {
					$provider = Abilities_Bridge_AI_Provider::infer_provider_from_model( $data->model, $provider );
				}
				$model = ! empty( $data->model ) ? $data->model : '';
			}
			if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider && ! empty( $data->last_openai_response_id ) ) {
				$previous_response_id = $data->last_openai_response_id;
			}
		}

		if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $provider ) {
			$model = Abilities_Bridge_OpenAI_API::normalize_model( $model );
		}
		$available = Abilities_Bridge_AI_Provider::get_available_models( $provider );
		if ( empty( $model ) || ! isset( $available[ $model ] ) ) {
			$model = Abilities_Bridge_AI_Provider::get_selected_model( $provider );
		}

		return compact( 'provider', 'model', 'previous_response_id' );
	}

	/**
	 * Submit one generation with the billing-risk-minimized retry policy.
	 *
	 * @param object      $client Provider client.
	 * @param array       $messages Provider-formatted messages.
	 * @param array       $tools Provider tool definitions.
	 * @param string      $model Model ID.
	 * @param string      $provider Provider key.
	 * @param object|null $job Durable job, if any.
	 * @param string      $lock Worker lock token.
	 * @return array|WP_Error
	 */
	private function send_with_billing_safe_retries( $client, $messages, $tools, $model, $provider, $job, $lock ) {
		$max_retries   = 3;
		$retry         = 0;
		$attempt_model = $model;
		$fallback_used = false;

		while ( $retry <= $max_retries ) {
			if ( $retry > 0 ) {
				sleep( (int) pow( 2, $retry - 1 ) );
			}

			if ( is_object( $job ) ) {
				$guard = $this->guard_worker( $job->id, $lock );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				$request_timeout = max( 1, (int) apply_filters( 'abilities_bridge_ai_request_timeout', 300 ) );
				if ( ! Abilities_Bridge_Chat_Jobs::extend_lease( $job->id, $lock, $request_timeout + 90 ) ) {
					return new WP_Error( 'job_lock_lost', __( 'The chat worker lost its execution lock.', 'abilities-bridge' ) );
				}
			}

			$response = $client->send_message( $messages, $tools, 4096, $attempt_model );
			if ( ! is_wp_error( $response ) ) {
				if ( $fallback_used && isset( $response['content'] ) && is_array( $response['content'] ) ) {
					array_unshift(
						$response['content'],
						array(
							'type' => 'text',
							'text' => __( '_Answered by Claude Opus 5 after Fable 5 declined the request._', 'abilities-bridge' ),
						)
					);
				}
				return $response;
			}

			$error_data = (array) $response->get_error_data();
			if ( 'ai_refusal' === $response->get_error_code() && ! $fallback_used && ! empty( $error_data['fallback_model'] ) ) {
				$attempt_model = (string) $error_data['fallback_model'];
				$fallback_used = true;
				$retry         = 0;
				if ( is_object( $job ) ) {
					Abilities_Bridge_Chat_Jobs::touch_activity( $job->id, $lock, __( 'Fable declined; waiting for Claude Opus', 'abilities-bridge' ) );
				}
				continue;
			}

			$response = $this->normalize_generation_error( $response, $provider );
			if ( in_array( $response->get_error_code(), array( 'openai_fetch_failed', 'openai_fetch_parse_error', 'openai_poll_timeout', 'openai_background_failed', 'job_cancelled' ), true ) ) {
				// These errors happen after OpenAI returned a stored response ID.
				// Any retry here would create a second generation; recovery uses GET.
				return $response;
			}
			$error_data     = (array) $response->get_error_data();
			$http_status    = isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$safe_transport = ! $http_status && self::is_safe_preconnect_failure( $response );

			// A pre-connect failure never reached the provider, and HTTP 429 is
			// an explicit rejection. Server/gateway failures are ambiguous and
			// normalize_generation_error() prevents a duplicate generation POST.
			$should_retry = $safe_transport || 429 === $http_status;
			if ( ! $should_retry || $retry === $max_retries ) {
				return $response;
			}
			++$retry;
		}

		return new WP_Error( 'api_error', __( 'The AI provider request failed.', 'abilities-bridge' ) );
	}

	/**
	 * Normalize errors whose provider-side generation outcome is unknown.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $provider Provider key.
	 * @return WP_Error
	 */
	private function normalize_generation_error( $error, $provider ) {
		$data   = (array) $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		$code   = $error->get_error_code();

		if ( in_array( $code, array( 'ai_timeout_ambiguous', 'ai_generation_ambiguous' ), true ) ) {
			return $error;
		}

		$is_transport_error = 'http_request_failed' === $code || false !== strpos( strtolower( $error->get_error_message() ), 'curl error' );
		if ( in_array( $status, array( 408, 504, 524 ), true ) || ( 0 === $status && $is_transport_error && ! self::is_safe_preconnect_failure( $error ) ) ) {
			return new WP_Error(
				'ai_timeout_ambiguous',
				__( 'The request timed out before the reply arrived. The AI may have finished the answer anyway (and billed it), so it was not retried automatically.', 'abilities-bridge' ),
				array(
					'status'   => $status,
					'provider' => $provider,
				)
			);
		}

		if ( $status >= 500 ) {
			return new WP_Error(
				'ai_generation_ambiguous',
				__( 'The AI provider returned a server error after the request was sent. It may still have completed and been billed, so it was not submitted again.', 'abilities-bridge' ),
				array(
					'status'   => $status,
					'provider' => $provider,
				)
			);
		}

		return $error;
	}

	/**
	 * Determine whether transport failed before a generation could be accepted.
	 *
	 * @param WP_Error $error Transport error.
	 * @return bool
	 */
	private static function is_safe_preconnect_failure( $error ) {
		$message = strtolower( $error->get_error_message() );
		$markers = array(
			'curl error 6',
			'curl error 7',
			'curl error 35',
			'could not resolve',
			'getaddrinfo failed',
			'php_network_getaddresses',
			'name or service not known',
			'nodename nor servname provided',
			'temporary failure in name resolution',
			'failed to connect',
			'connection refused',
			'network is unreachable',
			'no route to host',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( $message, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Re-check the durable cancellation and ownership guard.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $lock Lock token.
	 * @return true|WP_Error
	 */
	private function guard_worker( $job_id, $lock ) {
		if ( Abilities_Bridge_Chat_Jobs::worker_may_continue( $job_id, $lock ) ) {
			return true;
		}

		Abilities_Bridge_Chat_Jobs::mark_cancelled( $job_id, $lock );

		return new WP_Error( 'job_cancelled', __( 'The chat job was stopped.', 'abilities-bridge' ) );
	}

	/**
	 * Execute or finish the latest durable tool round.
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation.
	 * @param object                        $job Job.
	 * @param string                        $lock Lock.
	 * @param array                         $tool_usage Tool usage accumulator.
	 * @param array                         $last_results Result accumulator.
	 * @return true|WP_Error
	 */
	private function resume_checkpointed_tools( $conversation, $job, $lock, &$tool_usage, &$last_results ) {
		$round = Abilities_Bridge_Chat_Job_Steps::latest_round( $job->id );
		if ( $round <= 0 ) {
			return new WP_Error( 'missing_tool_checkpoint', __( 'The saved tool checkpoint is missing.', 'abilities-bridge' ) );
		}

		$steps = Abilities_Bridge_Chat_Job_Steps::get_for_job( $job->id, $round );
		foreach ( $steps as $step ) {
			$input        = json_decode( $step->input_json, true );
			$input        = is_array( $input ) ? $input : array();
			$tool_usage[] = array(
				'tool'  => $step->tool_name,
				'input' => $input,
			);

			if ( 'completed' === $step->status ) {
				continue;
			}
			if ( 'pending' !== $step->status ) {
				return new WP_Error( 'tool_outcome_ambiguous', __( 'A previously started tool has no durable result and cannot be run again safely.', 'abilities-bridge' ) );
			}

			$guard = $this->guard_worker( $job->id, $lock );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$tool_timeout = (int) apply_filters( 'abilities_bridge_tool_execution_timeout', 600, $step->tool_name );
			if ( ! Abilities_Bridge_Chat_Jobs::set_phase( $job->id, $lock, 'tool_inflight', $tool_timeout + 90 ) ) {
				return new WP_Error( 'job_cancelled', __( 'The chat job was stopped.', 'abilities-bridge' ) );
			}
			if ( ! Abilities_Bridge_Chat_Job_Steps::mark_running( $step->id, $job->id ) ) {
				return new WP_Error( 'tool_checkpoint_failed', __( 'The tool checkpoint could not be claimed.', 'abilities-bridge' ) );
			}
			$guard = $this->guard_worker( $job->id, $lock );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			Abilities_Bridge_Chat_Jobs::touch_activity(
				$job->id,
				$lock,
				sprintf(
					/* translators: %s: provider tool or ability name. */
					__( 'Calling ability: %s', 'abilities-bridge' ),
					$step->tool_name
				)
			);
			do_action( 'abilities_bridge_chat_job_checkpoint', 'before_tool', (int) $job->id, (int) $step->id );
			$result = $this->execute_tool( $step->tool_name, $input, (bool) $job->plan_mode );
			Abilities_Bridge_Logger::log_tool_execution( $step->tool_name, $input, $result, $job->conversation_id, 'admin' );
			do_action( 'abilities_bridge_chat_job_checkpoint', 'tool_returned', (int) $job->id, (int) $step->id );

			if ( ! Abilities_Bridge_Chat_Job_Steps::complete( $step->id, $job->id, $result ) ) {
				return new WP_Error( 'tool_result_persistence_failed', __( 'A tool finished, but its result could not be checkpointed safely.', 'abilities-bridge' ) );
			}

			if ( ! Abilities_Bridge_Chat_Jobs::worker_may_continue( $job->id, $lock ) ) {
				Abilities_Bridge_Chat_Jobs::mark_cancelled( $job->id, $lock );
				return new WP_Error( 'job_cancelled', __( 'The chat job was stopped after the running step finished.', 'abilities-bridge' ) );
			}
			Abilities_Bridge_Chat_Jobs::set_phase( $job->id, $lock, 'tools_pending', 90 );
		}

		$results = Abilities_Bridge_Chat_Job_Steps::build_result_blocks( $job->id, $round );
		if ( is_wp_error( $results ) ) {
			return $results;
		}
		$last_results = $results;

		if ( ! Abilities_Bridge_Chat_Jobs::acquire_conversation_lock( (int) $job->conversation_id, 2 ) ) {
			return new WP_Error( 'tool_result_persistence_failed', __( 'The tool results could not be serialized with the conversation transcript.', 'abilities-bridge' ) );
		}
		try {
			$guard = $this->guard_worker( $job->id, $lock );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$message_id = Abilities_Bridge_Database::upsert_job_tool_result_message(
				(int) $job->conversation_id,
				(int) $job->id,
				(int) $round,
				$results
			);
			if ( is_wp_error( $message_id ) ) {
				return new WP_Error(
					'tool_result_persistence_failed',
					__( 'The tool results were checkpointed, but they could not be added to the conversation safely.', 'abilities-bridge' )
				);
			}
		} finally {
			Abilities_Bridge_Chat_Jobs::release_conversation_lock( (int) $job->conversation_id );
		}
		$conversation->refresh_messages();

		if ( ! Abilities_Bridge_Chat_Jobs::set_phase( $job->id, $lock, 'ready', 90 ) ) {
			if ( Abilities_Bridge_Chat_Jobs::worker_may_continue( $job->id, $lock ) ) {
				return new WP_Error( 'tool_result_persistence_failed', __( 'The tool results were saved, but the durable resume checkpoint could not be finalized.', 'abilities-bridge' ) );
			}
			return new WP_Error( 'job_cancelled', __( 'The chat job was stopped.', 'abilities-bridge' ) );
		}
		do_action( 'abilities_bridge_chat_job_checkpoint', 'tools_persisted', (int) $job->id, 0 );

		return true;
	}

	/**
	 * Execute tools for the legacy synchronous path.
	 *
	 * @param Abilities_Bridge_Conversation $conversation Conversation.
	 * @param array                         $tool_uses Tool requests.
	 * @param bool                          $plan_mode Whether write-like tools are blocked.
	 * @param int                           $conversation_id Conversation ID.
	 * @param array                         $tool_usage Tool usage accumulator.
	 * @param array                         $last_results Result accumulator.
	 * @return true|WP_Error
	 */
	private function execute_synchronous_tools( $conversation, $tool_uses, $plan_mode, $conversation_id, &$tool_usage, &$last_results ) {
		$results = array();
		foreach ( $tool_uses as $tool_use ) {
			$name         = $tool_use['name'];
			$input        = isset( $tool_use['input'] ) ? $tool_use['input'] : array();
			$result       = $this->execute_tool( $name, $input, $plan_mode );
			$tool_usage[] = array(
				'tool'  => $name,
				'input' => $input,
			);
			Abilities_Bridge_Logger::log_tool_execution( $name, $input, $result, $conversation_id, 'admin' );
			$results[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $tool_use['id'],
				'content'     => wp_json_encode( $result ),
			);
		}

		$last_results = $results;
		$message_id   = $conversation->add_user_message_array( $results );

		return is_wp_error( $message_id ) ? $message_id : true;
	}

	/**
	 * Build a user-visible fallback when the model runs tools but returns no text.
	 *
	 * @param array $tool_usage Tool usage metadata.
	 * @param array $tool_results MCP-style tool results.
	 * @return string Fallback response.
	 */
	private static function build_tool_result_fallback_response( $tool_usage, $tool_results ) {
		$tool_names = array();

		foreach ( $tool_usage as $tool ) {
			if ( ! empty( $tool['tool'] ) ) {
				$tool_names[] = $tool['tool'];
			}
		}

		$tool_names = array_values( array_unique( $tool_names ) );
		$response   = 'I completed the requested tool action';

		if ( ! empty( $tool_names ) ) {
			$response .= ': ' . implode( ', ', $tool_names );
		}

		$response .= ".\n\nThe AI provider did not return a final text response, so here are the tool results:";

		$result_summaries = array();
		foreach ( $tool_results as $tool_result ) {
			if ( empty( $tool_result['content'] ) ) {
				continue;
			}

			$decoded = json_decode( $tool_result['content'], true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				$result_text = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			} else {
				$result_text = (string) $tool_result['content'];
			}

			$result_text = trim( wp_strip_all_tags( $result_text ) );
			if ( '' === $result_text ) {
				continue;
			}

			if ( strlen( $result_text ) > 900 ) {
				$result_text = substr( $result_text, 0, 900 ) . '...';
			}

			$result_summaries[] = $result_text;
		}

		if ( empty( $result_summaries ) ) {
			return $response . "\n\nNo tool result details were available.";
		}

		foreach ( array_slice( $result_summaries, 0, 3 ) as $index => $summary ) {
			$response .= "\n\nResult " . ( $index + 1 ) . ":\n" . $summary;
		}

		if ( count( $result_summaries ) > 3 ) {
			$response .= "\n\nAdditional tool results were completed but are not shown here.";
		}

		return $response;
	}

	/**
	 * Generate progress message for tool execution
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input Tool input.
	 * @return string Progress message
	 */
	private static function get_tool_progress_message( $tool_name, $input ) {
		switch ( $tool_name ) {
			case 'memory':
				$command = isset( $input['command'] ) ? $input['command'] : 'access';
				return "🧠 Memory: {$command}";

			default:
				return "🔧 Executing: {$tool_name}";
		}
	}

	/**
	 * Execute a tool function.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input Tool input parameters.
	 * @param bool   $plan_mode Whether plan mode is enabled (restricts write operations).
	 * @return array Result.
	 * @throws Exception When an unknown tool is requested.
	 */
	private function execute_tool( $tool_name, $input, $plan_mode = false ) {
		// Check if this is an ability execution request.
		if ( strpos( $tool_name, 'ability_' ) === 0 ) {
			$ability_name = Abilities_Bridge_Chat_Job_Steps::resolve_ability_name( $tool_name );
			if ( null === $ability_name ) {
				return array(
					'success' => false,
					'error'   => 'The requested WordPress ability is not enabled or could not be resolved.',
				);
			}
			if ( $plan_mode && ! Abilities_Bridge_Chat_Job_Steps::is_readonly_tool( $tool_name ) ) {
				return array(
					'success'   => false,
					'error'     => 'Write-capable WordPress abilities are disabled in Plan Mode.',
					'plan_mode' => true,
				);
			}

			// Validate input.
			if ( is_object( $input ) ) {
				$input = (array) $input;
			}
			if ( is_null( $input ) ) {
				$input = array();
			}

			// Pass input directly to orchestrator - abilities use their native input schemas.
			// No wrapper required (parameters/reason wrapper removed to match MCP server behavior).
			return $this->orchestrator->execute_ability_request(
				$ability_name,
				$input,  // Direct parameters matching the ability's input schema.
				'Executed via WordPress chat',
				null // No conversation ID context needed here.
			);
		}

		// Plan Mode restrictions - simple and clear.
		if ( $plan_mode ) {
			$blocked_tools = array(
				'execute_wp_cli' => 'WP-CLI commands are disabled in Plan Mode',
				'execute_php'    => 'PHP execution is disabled in Plan Mode',
				'write_file'     => 'File writing is disabled in Plan Mode',
				'database_query' => 'Database queries are disabled in Plan Mode',
			);

			if ( isset( $blocked_tools[ $tool_name ] ) ) {
				return array(
					'success'   => false,
					'error'     => $blocked_tools[ $tool_name ],
					'plan_mode' => true,
				);
			}
		}

		// Initialize result.
		$result = array(
			'success' => false,
			'error'   => 'Tool execution failed',
		);

		try {
			// Validate input parameters - convert stdClass objects to arrays.
			if ( is_object( $input ) ) {
				$input = (array) $input;
			}

			// Allow null input for tools that don't require parameters.
			if ( is_null( $input ) ) {
				$input = array();
			}

			if ( ! is_array( $input ) ) {
				throw new Exception( 'Invalid input parameters: must be an array or object' );
			}

			switch ( $tool_name ) {
				case 'memory':
					// Check if memory is enabled.
					if ( ! get_option( 'abilities_bridge_enable_memory', false ) ) {
						$result = array(
							'success' => false,
							'error'   => 'Memory tool is disabled. Enable it in Settings > Memory to use persistent memory.',
						);
						break;
					}

					// Check if user has given consent.
					if ( ! get_option( 'abilities_bridge_memory_consent', false ) ) {
						$result = array(
							'success' => false,
							'error'   => 'Memory tool requires user consent. Please enable and accept the consent in Settings.',
						);
						break;
					}

					require_once ABILITIES_BRIDGE_PLUGIN_DIR . 'includes/class-abilities-bridge-memory-functions.php';
					$result = Abilities_Bridge_Memory_Functions::execute( $input );
					break;

				default:
					$result = array(
						'success' => false,
						'error'   => "Unknown tool: {$tool_name}. Available tools: memory",
					);
			}

			// Ensure result is properly formatted.
			if ( ! is_array( $result ) ) {
				$result = array(
					'success'    => false,
					'error'      => 'Tool returned invalid response format',
					'raw_result' => wp_json_encode( $result ),
				);
			}

			// Ensure result has success flag.
			if ( ! isset( $result['success'] ) ) {
				$result['success'] = false;
				$result['error']   = 'Tool did not return success status';
			}
		} catch ( Exception $e ) {
			$result = array(
				'success' => false,
				'error'   => 'Exception: ' . $e->getMessage(),
				'tool'    => $tool_name,
				'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
			);
		} catch ( Error $e ) {
			$result = array(
				'success' => false,
				'error'   => 'Fatal Error: ' . $e->getMessage(),
				'tool'    => $tool_name,
				'trace'   => substr( $e->getTraceAsString(), 0, 2000 ),
			);
		}

		// Truncate large error messages.
		if ( isset( $result['error'] ) && strlen( $result['error'] ) > 5000 ) {
			$result['error'] = substr( $result['error'], 0, 5000 ) . '... [truncated]';
		}

		// Truncate large output.
		if ( isset( $result['output'] ) && strlen( $result['output'] ) > 500000 ) {
			$result['output'] = substr( $result['output'], 0, 500000 ) . '... [output truncated]';
		}

		return $result;
	}

	/**
	 * Convert technical error to user-friendly message
	 *
	 * @param WP_Error $error The error object.
	 * @return string User-friendly error message
	 */
	private static function get_user_friendly_error( $error ) {
		$error_code = $error->get_error_code();
		$error_data = $error->get_error_data();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			Abilities_Bridge_Logger::log_action(
				null,
				'ai_error_debug',
				array(
					'code' => $error_code,
					'data' => $error_data,
				)
			);
		}

		$friendly_messages = array(
			'ai_timeout_ambiguous'       => 'The request timed out before the reply arrived. The AI may have finished it anyway (and billed it), so it was not retried automatically.',
			'ai_generation_ambiguous'    => 'The AI request outcome could not be confirmed safely. It may already have been billed, so it was not retried automatically.',
			'ai_refusal'                 => 'The AI model declined this request through its safety system. Rewording the request usually resolves this.',
			'job_cancelled'              => 'The chat job was stopped.',
			'message_persistence_failed' => 'The message could not be saved. Please try again.',
			'json_parse_error'           => 'Unable to connect to AI service. Please try again.',
			'invalid_response_structure' => 'Received unexpected response. Please try again.',
			'no_api_key'                 => 'API key not configured. Please check Settings.',
			'overloaded_error'           => 'AI service is busy. Please wait a moment and try again.',
			'rate_limit_error'           => 'Rate limit reached. Please wait before sending another message.',
			'invalid_api_key'            => 'Invalid API key. Please check your settings.',
			'insufficient_quota'         => 'Insufficient quota. Please check your AI provider billing.',
			'billing_hard_limit_reached' => 'Billing limit reached. Please check your AI provider billing.',
			'invalid_request_error'      => 'OpenAI request is invalid. Check your model and settings.',
			'model_not_found'            => 'Selected OpenAI model is unavailable. Choose a different model in settings.',
			'context_length_exceeded'    => 'Conversation is too long for the selected model. Start a new conversation or summarize.',
			'authentication_error'       => 'Invalid OpenAI API key. Please check your settings.',
			'permission_error'           => 'OpenAI API key does not have permission for the selected model.',
			'requests'                   => 'Rate limit reached. Please wait before sending another message.',
		);

		if ( isset( $friendly_messages[ $error_code ] ) && in_array( $error_code, array( 'ai_timeout_ambiguous', 'ai_generation_ambiguous' ), true ) ) {
			return $friendly_messages[ $error_code ];
		}

		if ( isset( $error_data['status'] ) ) {
			$status = $error_data['status'];
			if ( 401 === $status ) {
				return 'Invalid API key. Please check your settings.';
			} elseif ( 400 === $status ) {
				$provider_message = isset( $error_data['provider_message'] ) ? sanitize_text_field( $error_data['provider_message'] ) : '';
				if ( ! empty( $provider_message ) && current_user_can( 'manage_options' ) ) {
					return $provider_message;
				}
				return 'Invalid AI request. Check your selected model and provider settings.';
			} elseif ( 429 === $status ) {
				return 'Rate limit reached. Please wait a moment.';
			} elseif ( $status >= 500 ) {
				return 'AI service temporarily unavailable. Please try again.';
			}
		}

		return isset( $friendly_messages[ $error_code ] )
			? $friendly_messages[ $error_code ]
			: 'Unable to process request. Please try again.';
	}
}
