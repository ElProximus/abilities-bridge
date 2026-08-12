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

	const CRON_HOOK = 'abilities_bridge_run_chat_job';

	/**
	 * Register loopback and cron entry points.
	 */
	public function register() {
		add_action( 'wp_ajax_abilities_bridge_chat_job_run', array( $this, 'handle_loopback' ) );
		add_action( 'wp_ajax_nopriv_abilities_bridge_chat_job_run', array( $this, 'handle_loopback' ) );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron' ), 10, 2 );
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
			if ( Abilities_Bridge_Chat_Jobs::worker_may_continue( $job_id, $lock ) ) {
				Abilities_Bridge_Chat_Jobs::fail( $job_id, $lock, 'worker_exception', $throwable->getMessage() );
			} else {
				Abilities_Bridge_Chat_Jobs::mark_cancelled( $job_id, $lock );
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
