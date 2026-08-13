<?php
/**
 * Durable background transport for chat jobs.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatches and runs exactly one already-prepared chat job.
 */
class Abilities_Bridge_Chat_Job_Runner {

	const CRON_HOOK          = 'abilities_bridge_run_chat_job';
	const RECOVERY_CRON_HOOK = 'abilities_bridge_recover_openai_chat_job';

	/**
	 * Register loopback and cron entry points.
	 */
	public function register() {
		add_action( 'wp_ajax_abilities_bridge_chat_job_run', array( $this, 'handle_loopback' ) );
		add_action( 'wp_ajax_nopriv_abilities_bridge_chat_job_run', array( $this, 'handle_loopback' ) );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron' ), 10, 2 );
		add_action( self::RECOVERY_CRON_HOOK, array( $this, 'handle_recovery_cron' ), 10, 2 );
	}

	/**
	 * Schedule a billing-safe GET recovery check for an accepted OpenAI response.
	 *
	 * @param int      $job_id Job ID.
	 * @param int|null $delay  Seconds before the check.
	 * @return bool Whether a check is scheduled or already pending.
	 */
	public static function schedule_recovery( $job_id, $delay = null ) {
		$job = Abilities_Bridge_Chat_Jobs::get( $job_id );
		if ( ! $job || 'openai' !== $job->provider || 'provider_inflight' !== $job->phase || empty( $job->provider_response_id ) || (int) $job->cancel_requested ) {
			return false;
		}

		if ( ! in_array( $job->status, array( 'running', 'uncertain' ), true ) || 'exhausted' === $job->recovery_status ) {
			return false;
		}

		if ( Abilities_Bridge_Chat_Jobs::age( $job ) >= Abilities_Bridge_Chat_Jobs::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS ) {
			if ( 'uncertain' === $job->status ) {
				Abilities_Bridge_Chat_Jobs::mark_recovery_exhausted( $job->id );
			}
			return false;
		}

		$delay = null === $delay ? Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS : max( 1, (int) $delay );
		if ( 'running' === $job->status && ! empty( $job->lease_expires_at ) ) {
			$lease_delay = strtotime( $job->lease_expires_at . ' UTC' ) - time() + 1;
			$delay       = max( $delay, $lease_delay );
		}

		$args = array( (int) $job->id, (string) $job->runner_token );
		if ( wp_next_scheduled( self::RECOVERY_CRON_HOOK, $args ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time() + $delay, self::RECOVERY_CRON_HOOK, $args );
	}

	/**
	 * Kick the primary non-blocking request and a cron backstop.
	 *
	 * @param int  $job_id Job ID.
	 * @param bool $force Initial dispatch bypasses the throttle.
	 * @return bool Whether a dispatch was issued.
	 */
	public static function dispatch( $job_id, $force = false ) {
		$job = Abilities_Bridge_Chat_Jobs::get( $job_id );
		if ( ! $job || 'queued' !== $job->status || ! Abilities_Bridge_Chat_Jobs::mark_dispatched( $job_id, $force ) ) {
			return false;
		}

		$args = array( (int) $job->id, (string) $job->runner_token );
		if ( ! wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK, $args );
		}

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 1,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action'       => 'abilities_bridge_chat_job_run',
					'job_id'       => (int) $job->id,
					'runner_token' => (string) $job->runner_token,
				),
			)
		);

		return true;
	}

	/**
	 * Internal loopback endpoint. The opaque per-job token is its only auth.
	 */
	public function handle_loopback() {
		// This nopriv loopback intentionally has no WordPress nonce or logged-in
		// context. Its 256-bit per-job token and atomic claim are the auth layer.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0;
		$token  = isset( $_POST['runner_token'] ) ? sanitize_text_field( wp_unslash( $_POST['runner_token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->run_existing( $job_id, $token );
		wp_die();
	}

	/**
	 * Cron backstop.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $runner_token Runner token.
	 */
	public function handle_cron( $job_id, $runner_token ) {
		$this->run_existing( (int) $job_id, (string) $runner_token );
	}

	/**
	 * Scheduled recovery entry point for a stored OpenAI response.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $runner_token Recovery authority.
	 * @return void
	 */
	public function handle_recovery_cron( $job_id, $runner_token ) {
		$job = Abilities_Bridge_Chat_Jobs::get( (int) $job_id );
		if ( ! $job || '' === (string) $runner_token || ! hash_equals( (string) $job->runner_token, (string) $runner_token ) ) {
			return;
		}

		if ( 'running' === $job->status ) {
			Abilities_Bridge_Chat_Jobs::sweep( $job->id );
			$job = Abilities_Bridge_Chat_Jobs::get( $job->id );
		}

		if ( $job && Abilities_Bridge_Chat_Jobs::is_openai_recovery_pending( $job ) ) {
			self::recover_openai_job( $job );
		}

		self::schedule_recovery( (int) $job_id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
	}

	/**
	 * Billing-safe GET recovery for a stored OpenAI response.
	 *
	 * This may persist model output and queue tool work, but it never executes
	 * an ability. The normal worker remains the only tool executor.
	 *
	 * @param object $job Uncertain OpenAI job.
	 * @return string completed|pending|exhausted|deferred|ineligible
	 */
	public static function recover_openai_job( $job ) {
		if ( ! Abilities_Bridge_Chat_Jobs::is_openai_recovery_pending( $job ) ) {
			if ( $job && 'uncertain' === $job->status && Abilities_Bridge_Chat_Jobs::age( $job ) >= Abilities_Bridge_Chat_Jobs::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS ) {
				Abilities_Bridge_Chat_Jobs::mark_recovery_exhausted( $job->id );
			}
			return 'ineligible';
		}

		if ( ! Abilities_Bridge_Chat_Jobs::begin_recovery( $job->id ) ) {
			self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
			return 'deferred';
		}

		$previous_user_id = get_current_user_id();
		wp_set_current_user( (int) $job->user_id );

		try {
			$client  = Abilities_Bridge_AI_Provider::create_client( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI );
			$request = $client->fetch_background_response( $job->provider_response_id );
			if ( is_wp_error( $request ) ) {
				$data      = (array) $request->get_error_data();
				$exhausted = isset( $data['status'] ) && in_array( (int) $data['status'], array( 400, 401, 403, 404 ), true );
				Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, $exhausted );
				if ( ! $exhausted ) {
					self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
				}
				return $exhausted ? 'exhausted' : 'pending';
			}

			$status = $request['data']['status'] ?? '';
			if ( in_array( $status, array( 'queued', 'in_progress' ), true ) ) {
				Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, false );
				self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
				return 'pending';
			}
			if ( in_array( $status, array( 'failed', 'cancelled', 'incomplete' ), true ) ) {
				Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, true );
				return 'exhausted';
			}

			$parsed = $client->parse_response_data( $request['data'], $request['response_body'], $request['response_code'] );
			if ( is_wp_error( $parsed ) ) {
				Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, true );
				return 'exhausted';
			}

			if ( ! Abilities_Bridge_Chat_Jobs::acquire_conversation_lock( (int) $job->conversation_id, 2 ) ) {
				Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, false );
				self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
				return 'pending';
			}

			try {
				$current = Abilities_Bridge_Chat_Jobs::get( $job->id );
				if ( ! $current || 'recovering' !== $current->recovery_status || (int) $current->cancel_requested ) {
					Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, true );
					return 'exhausted';
				}

				$tool_uses = array();
				foreach ( $parsed['content'] as $block ) {
					if ( isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
						$tool_uses[] = $block;
					}
				}
				if ( ! empty( $tool_uses ) ) {
					$seeded = Abilities_Bridge_Chat_Job_Steps::seed( $job->id, (int) $job->rounds_completed + 1, $tool_uses );
					if ( is_wp_error( $seeded ) ) {
						Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, true );
						return 'exhausted';
					}
				}

				$round_number = (int) $job->rounds_completed + 1;
				$message_id   = Abilities_Bridge_Database::get_job_assistant_message_id( $job->id, $round_number );
				if ( ! $message_id ) {
					$conversation = new Abilities_Bridge_Conversation( $job->conversation_id );
					$message_id   = $conversation->add_assistant_message( $parsed['content'], $job->id, $round_number );
				}
				if ( is_wp_error( $message_id ) ) {
					Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, false );
					self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
					return 'pending';
				}

				if ( ! Abilities_Bridge_Chat_Jobs::finish_recovered_response( $job->id, ! empty( $tool_uses ) ) ) {
					Abilities_Bridge_Chat_Jobs::reconcile_terminal_context( $job->id );
					Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, true );
					return 'exhausted';
				}
				Abilities_Bridge_Database::show_job_messages_in_context( $job->id );
				if ( ! empty( $parsed['response_id'] ) ) {
					Abilities_Bridge_Database::update_last_openai_response_id( $job->conversation_id, $parsed['response_id'] );
				}
			} finally {
				Abilities_Bridge_Chat_Jobs::release_conversation_lock( (int) $job->conversation_id );
			}

			if ( ! empty( $tool_uses ) ) {
				$recovered_job = Abilities_Bridge_Chat_Jobs::get( $job->id );
				self::dispatch( $recovered_job->id, true );
			}

			return 'completed';
		} catch ( Throwable $throwable ) {
			Abilities_Bridge_Logger::log_error( (int) $job->conversation_id, 'OpenAI response recovery', $throwable );
			Abilities_Bridge_Chat_Jobs::finish_recovery_attempt( $job->id, false );
			self::schedule_recovery( $job->id, Abilities_Bridge_Chat_Jobs::RECOVERY_RETRY_SECONDS );
			return 'pending';
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}

	/**
	 * Claim and run the same prepared job for loopback, cron, or foreground takeover.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $runner_token Dispatch authority.
	 * @return true|WP_Error
	 */
	public function run_existing( $job_id, $runner_token ) {
		$job = Abilities_Bridge_Chat_Jobs::get( $job_id );
		if ( ! $job || '' === $runner_token || ! hash_equals( (string) $job->runner_token, (string) $runner_token ) ) {
			return new WP_Error( 'invalid_runner_token', __( 'The chat runner token is invalid.', 'abilities-bridge' ) );
		}

		$lock = Abilities_Bridge_Chat_Jobs::claim( $job_id, $runner_token );
		if ( ! $lock ) {
			return new WP_Error( 'job_not_claimed', __( 'The chat job is no longer available to run.', 'abilities-bridge' ) );
		}

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hosts may disable this function.
		}

		$job               = Abilities_Bridge_Chat_Jobs::get( $job_id );
		$previous_user_id  = get_current_user_id();
		$timeout_filter    = static function () {
			return 300;
		};
		$background_filter = static function () use ( $job ) {
			return Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $job->provider;
		};
		$response_listener = static function ( $response_id ) use ( $job_id, $lock ) {
			Abilities_Bridge_Chat_Jobs::store_provider_response_id( $job_id, $lock, $response_id );
		};
		$poll_guard        = static function () use ( $job_id, $lock ) {
			return Abilities_Bridge_Chat_Jobs::worker_may_continue( $job_id, $lock );
		};
		$poll_tick         = static function () use ( $job_id, $lock ) {
			Abilities_Bridge_Chat_Jobs::extend_lease( $job_id, $lock, 390 );
		};

		wp_set_current_user( (int) $job->user_id );
		add_filter( 'abilities_bridge_ai_request_timeout', $timeout_filter );
		add_filter( 'abilities_bridge_openai_background_mode', $background_filter );
		add_action( 'abilities_bridge_openai_response_submitted', $response_listener );
		add_filter( 'abilities_bridge_openai_continue_polling', $poll_guard );
		add_action( 'abilities_bridge_openai_poll_tick', $poll_tick );

		try {
			if ( ! Abilities_Bridge_Chat_Jobs::mark_running( $job_id, $lock ) ) {
				return new WP_Error( 'job_not_running', __( 'The chat job could not enter the running state.', 'abilities-bridge' ) );
			}

			$conversation = new Abilities_Bridge_Conversation( $job->conversation_id );
			if ( ! $conversation->get_id() ) {
				Abilities_Bridge_Chat_Jobs::fail( $job_id, $lock, 'conversation_not_found', __( 'The conversation no longer exists.', 'abilities-bridge' ) );
				return new WP_Error( 'conversation_not_found', __( 'The conversation no longer exists.', 'abilities-bridge' ) );
			}

			$processor = new Abilities_Bridge_Message_Processor();
			$result    = $processor->run_loop( $conversation, Abilities_Bridge_Chat_Jobs::get( $job_id ), $lock );
			if ( true === $result ) {
				Abilities_Bridge_Chat_Jobs::complete( $job_id, $lock );
				return true;
			}

			if ( 'job_cancelled' === $result->get_error_code() ) {
				Abilities_Bridge_Chat_Jobs::mark_cancelled( $job_id, $lock );
				return $result;
			}

			if ( in_array( $result->get_error_code(), array( 'ai_timeout_ambiguous', 'ai_generation_ambiguous', 'provider_checkpoint_failed', 'tool_outcome_ambiguous', 'tool_result_persistence_failed', 'openai_poll_timeout', 'openai_fetch_failed', 'openai_fetch_parse_error' ), true ) ) {
				Abilities_Bridge_Chat_Jobs::mark_uncertain( $job_id, $lock, $result->get_error_code(), $result->get_error_message() );
			} else {
				Abilities_Bridge_Chat_Jobs::fail( $job_id, $lock, $result->get_error_code(), $result->get_error_message() );
			}

			return $result;
		} catch ( Throwable $throwable ) {
			Abilities_Bridge_Logger::log_error( (int) $job->conversation_id, 'background chat worker', $throwable );
			$outcome = Abilities_Bridge_Chat_Jobs::handle_worker_exception( $job_id, $lock );
			if ( 'requeued' === $outcome ) {
				self::dispatch( $job_id, true );
			}
			return new WP_Error( 'worker_exception', __( 'The background chat worker stopped unexpectedly.', 'abilities-bridge' ) );
		} finally {
			remove_filter( 'abilities_bridge_ai_request_timeout', $timeout_filter );
			remove_filter( 'abilities_bridge_openai_background_mode', $background_filter );
			remove_action( 'abilities_bridge_openai_response_submitted', $response_listener );
			remove_filter( 'abilities_bridge_openai_continue_polling', $poll_guard );
			remove_action( 'abilities_bridge_openai_poll_tick', $poll_tick );
			wp_set_current_user( $previous_user_id );
		}
	}
}
