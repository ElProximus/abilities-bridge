<?php
/**
 * Durable chat job ledger and atomic state transitions.
 *
 * @package Abilities_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns durable job state. Provider calls and abilities never happen here.
 */
class Abilities_Bridge_Chat_Jobs {

	const DISPATCH_STALE_SECONDS  = 60;
	const REDISPATCH_SECONDS      = 15;
	const PREPARING_STALE_SECONDS = 60;
	const TERMINAL_RETENTION_DAYS = 30;
	const UNCERTAIN_RESUME_HOURS  = 24;
	const RECOVERY_RETRY_SECONDS  = 60;
	const RECOVERY_STALE_SECONDS  = 180;

	/**
	 * Per-request depth for re-entrant conversation advisory locks.
	 *
	 * Older MySQL/MariaDB releases allow only one named lock per connection.
	 * Using one conversation lock name and avoiding nested SQL GET_LOCK calls
	 * preserves enqueue serialization on those releases as well.
	 *
	 * @var array
	 */
	private static $conversation_lock_depth = array();

	/**
	 * Get one job.
	 *
	 * @param int $id Job ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id )
		);
	}

	/**
	 * Get a job by public browser token.
	 *
	 * @param string $token Public token.
	 * @return object|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE public_token = %s', self::table(), (string) $token )
		);
	}

	/**
	 * Acquire the shared advisory lock for one conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $timeout_seconds Maximum wait in seconds.
	 * @return bool
	 */
	public static function acquire_conversation_lock( $conversation_id, $timeout_seconds = 2 ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		if ( $conversation_id <= 0 ) {
			return false;
		}
		if ( ! empty( self::$conversation_lock_depth[ $conversation_id ] ) ) {
			++self::$conversation_lock_depth[ $conversation_id ];
			return true;
		}

		$mutex_name = 'ab_chat_' . $conversation_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- short cross-request conversation mutex.
		$locked = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $mutex_name, max( 0, (int) $timeout_seconds ) ) );
		if ( $locked ) {
			self::$conversation_lock_depth[ $conversation_id ] = 1;
		}

		return $locked;
	}

	/**
	 * Release one depth of the shared conversation advisory lock.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function release_conversation_lock( $conversation_id ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		if ( empty( self::$conversation_lock_depth[ $conversation_id ] ) ) {
			return false;
		}

		--self::$conversation_lock_depth[ $conversation_id ];
		if ( self::$conversation_lock_depth[ $conversation_id ] > 0 ) {
			return true;
		}
		unset( self::$conversation_lock_depth[ $conversation_id ] );

		$mutex_name = 'ab_chat_' . $conversation_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- release cross-request conversation mutex.
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex_name ) );
	}

	/**
	 * Create a preparing row before any message is persisted.
	 *
	 * @param array $args Job values.
	 * @return object|WP_Error
	 */
	public static function create_preparing( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'conversation_id' => 0,
				'user_id'         => 0,
				'provider'        => '',
				'model'           => '',
				'plan_mode'       => false,
			)
		);

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- durable custom-table ledger.
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'conversation_id' => (int) $args['conversation_id'],
				'user_id'         => (int) $args['user_id'],
				'status'          => 'preparing',
				'phase'           => 'ready',
				'provider'        => sanitize_key( $args['provider'] ),
				'model'           => sanitize_text_field( $args['model'] ),
				'plan_mode'       => $args['plan_mode'] ? 1 : 0,
				'public_token'    => self::generate_token(),
				'runner_token'    => self::generate_token(),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'chat_job_create_failed', __( 'Unable to create the background chat job.', 'abilities-bridge' ) );
		}

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Finish enqueue after the checked user message exists.
	 *
	 * @param int    $id              Job ID.
	 * @param int    $user_message_id Message ID.
	 * @param string $attachment_json Stored attachment metadata.
	 * @return bool
	 */
	public static function activate_prepared( $id, $user_message_id, $attachment_json = '' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'queued', user_message_id = %d, attachment_json = %s,
				updated_at = %s WHERE id = %d AND status = 'preparing'",
				self::table(),
				(int) $user_message_id,
				(string) $attachment_json,
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Checkpoint stored attachment metadata before persisting the user message.
	 *
	 * @param int    $id Job ID.
	 * @param string $attachment_json Stored attachment blocks.
	 * @return bool
	 */
	public static function store_preparing_attachments( $id, $attachment_json ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- enqueue checkpoint.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET attachment_json = %s, updated_at = %s WHERE id = %d AND status = 'preparing'",
				self::table(),
				(string) $attachment_json,
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Delete a preparing row after an enqueue rollback.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function delete_preparing( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- enqueue rollback.
		$deleted = $wpdb->delete(
			self::table(),
			array(
				'id'     => (int) $id,
				'status' => 'preparing',
			),
			array( '%d', '%s' )
		);

		return false !== $deleted;
	}

	/**
	 * Find an active blocker that has not been cancelled.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return object|null
	 */
	public static function find_blocking( $conversation_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- durable custom-table ledger.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE conversation_id = %d
				AND status IN ('preparing','queued','dispatching','running')
				AND cancel_requested = 0 ORDER BY id DESC LIMIT 1",
				self::table(),
				(int) $conversation_id
			)
		);
	}

	/**
	 * Cancel every active job before its conversation is deleted.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function cancel_for_conversation( $conversation_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle lookup.
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE conversation_id = %d AND status IN ('preparing','queued','dispatching','running')",
				self::table(),
				(int) $conversation_id
			)
		);
		foreach ( $ids as $id ) {
			self::request_cancel( (int) $id );
		}
	}

	/**
	 * Link earlier cancelled work to its replacement turn.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $new_job_id      Replacement job ID.
	 * @return void
	 */
	public static function mark_superseded( $conversation_id, $new_job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET superseded_by_job_id = %d, updated_at = %s
				WHERE conversation_id = %d AND id < %d AND cancel_requested = 1 AND superseded_by_job_id = 0',
				self::table(),
				(int) $new_job_id,
				current_time( 'mysql', true ),
				(int) $conversation_id,
				(int) $new_job_id
			)
		);

		// A recoverable uncertain OpenAI job does not block a new question, but
		// once a replacement exists it must not publish a late recovered answer
		// into the newer exchange.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- supersede recovery fence.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET cancel_requested = 1, recovery_status = 'exhausted',
				superseded_by_job_id = %d, updated_at = %s
				WHERE conversation_id = %d AND id < %d AND status = 'uncertain' AND cancel_requested = 0",
				self::table(),
				(int) $new_job_id,
				current_time( 'mysql', true ),
				(int) $conversation_id,
				(int) $new_job_id
			)
		);
	}

	/**
	 * Throttle dispatch attempts.
	 *
	 * @param int  $id    Job ID.
	 * @param bool $force Whether this is the initial dispatch.
	 * @return bool
	 */
	public static function mark_dispatched( $id, $force = false ) {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REDISPATCH_SECONDS );
		if ( $force ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- initial dispatch.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET last_dispatched_at = %s, updated_at = %s WHERE id = %d AND status = 'queued'",
					self::table(),
					$now,
					$now,
					(int) $id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- redispatch throttle.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET last_dispatched_at = %s, updated_at = %s
					WHERE id = %d AND status = 'queued' AND (last_dispatched_at IS NULL OR last_dispatched_at <= %s)",
					self::table(),
					$now,
					$now,
					(int) $id,
					$cutoff
				)
			);
		}

		return 1 === $updated;
	}

	/**
	 * Atomically claim a queued job using dispatch authority.
	 *
	 * @param int    $id           Job ID.
	 * @param string $runner_token Runner token.
	 * @return string|false Lock token or false.
	 */
	public static function claim( $id, $runner_token ) {
		global $wpdb;

		$lock = self::generate_token();
		$now  = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'dispatching', lock_token = %s, heartbeat_at = %s, updated_at = %s
				WHERE id = %d AND runner_token = %s AND status = 'queued' AND cancel_requested = 0",
				self::table(),
				$lock,
				$now,
				$now,
				(int) $id,
				(string) $runner_token
			)
		);

		return 1 === $updated ? $lock : false;
	}

	/**
	 * Move a claimed worker into running before any provider/tool operation.
	 *
	 * @param int    $id   Job ID.
	 * @param string $lock Lock token.
	 * @return bool
	 */
	public static function mark_running( $id, $lock ) {
		global $wpdb;

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', started_at = COALESCE(started_at, %s), heartbeat_at = %s,
				lease_expires_at = %s, updated_at = %s WHERE id = %d AND lock_token = %s
				AND status = 'dispatching' AND cancel_requested = 0",
				self::table(),
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + self::DISPATCH_STALE_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now ),
				(int) $id,
				(string) $lock
			)
		);

		return 1 === $updated;
	}

	/**
	 * Set an execution phase and lease before entering a dangerous window.
	 *
	 * @param int    $id            Job ID.
	 * @param string $lock          Lock token.
	 * @param string $phase         Phase.
	 * @param int    $lease_seconds Lease duration.
	 * @return bool
	 */
	public static function set_phase( $id, $lock, $phase, $lease_seconds ) {
		global $wpdb;

		$allowed = array( 'ready', 'provider_inflight', 'tools_pending', 'tool_inflight' );
		if ( ! in_array( $phase, $allowed, true ) ) {
			return false;
		}

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic custom-table transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET phase = %s, heartbeat_at = %s, lease_expires_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = %s AND cancel_requested = 0',
				self::table(),
				$phase,
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + max( 30, (int) $lease_seconds ) ),
				gmdate( 'Y-m-d H:i:s', $now ),
				(int) $id,
				(string) $lock,
				'running'
			)
		);

		return 1 === $updated;
	}

	/**
	 * Extend the current lease without changing phase.
	 *
	 * @param int    $id            Job ID.
	 * @param string $lock          Lock token.
	 * @param int    $lease_seconds Lease duration.
	 * @return bool
	 */
	public static function extend_lease( $id, $lock, $lease_seconds ) {
		global $wpdb;

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- worker heartbeat.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET heartbeat_at = %s, lease_expires_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = %s AND cancel_requested = 0',
				self::table(),
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + max( 30, (int) $lease_seconds ) ),
				gmdate( 'Y-m-d H:i:s', $now ),
				(int) $id,
				(string) $lock,
				'running'
			)
		);

		if ( 1 === $updated ) {
			return true;
		}

		// MySQL reports zero affected rows when an immediate renewal produces the
		// same second-precision timestamps. Distinguish that harmless no-op from
		// a genuinely lost lease before aborting the worker.
		return 0 === $updated && self::worker_may_continue( $id, $lock );
	}

	/**
	 * Update safe human progress text.
	 *
	 * @param int    $id       Job ID.
	 * @param string $lock     Lock token.
	 * @param string $activity Activity text.
	 * @return bool
	 */
	public static function touch_activity( $id, $lock, $activity ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- worker progress.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_activity = %s, heartbeat_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = %s',
				self::table(),
				sanitize_text_field( $activity ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $lock,
				'running'
			)
		);

		return 1 === $updated;
	}

	/**
	 * Persist a provider-side response ID as soon as it is known.
	 *
	 * @param int    $id          Job ID.
	 * @param string $lock        Lock token.
	 * @param string $response_id Provider ID.
	 * @return bool
	 */
	public static function store_provider_response_id( $id, $lock, $response_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recovery pointer.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET provider_response_id = %s, recovery_status = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = %s AND cancel_requested = 0',
				self::table(),
				sanitize_text_field( $response_id ),
				'',
				current_time( 'mysql', true ),
				(int) $id,
				(string) $lock,
				'running'
			)
		);

		if ( 1 === $updated ) {
			Abilities_Bridge_Chat_Job_Runner::schedule_recovery( $id );
		}

		return 1 === $updated;
	}

	/**
	 * Atomically publish a persisted provider round and its safe next phase.
	 *
	 * @param int    $id Job ID.
	 * @param string $lock Worker lock.
	 * @param string $next_phase Ready, tools_pending, or completed.
	 * @return int|false New durable round count.
	 */
	public static function checkpoint_provider_round( $id, $lock, $next_phase ) {
		global $wpdb;

		if ( ! in_array( $next_phase, array( 'ready', 'tools_pending', 'completed' ), true ) ) {
			return false;
		}

		$now           = time();
		$target_status = 'completed' === $next_phase ? 'completed' : 'running';
		$phase         = 'completed' === $next_phase ? 'ready' : $next_phase;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic provider checkpoint.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, phase = %s, rounds_completed = rounds_completed + 1,
				heartbeat_at = %s, lease_expires_at = %s,
				completed_at = CASE WHEN %s = 'completed' THEN %s ELSE completed_at END, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'running'
				AND phase = 'provider_inflight' AND cancel_requested = 0",
				self::table(),
				$target_status,
				$phase,
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + 90 ),
				$target_status,
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now ),
				(int) $id,
				(string) $lock
			)
		);

		if ( 1 !== $updated ) {
			return false;
		}

		$job = self::get( $id );

		return $job ? (int) $job->rounds_completed : false;
	}

	/**
	 * Whether this worker still owns a live, uncancelled job.
	 *
	 * @param int    $id   Job ID.
	 * @param string $lock Lock token.
	 * @return bool
	 */
	public static function worker_may_continue( $id, $lock ) {
		$job = self::get( $id );

		return $job && 'running' === $job->status && 0 === (int) $job->cancel_requested && hash_equals( (string) $job->lock_token, (string) $lock );
	}

	/**
	 * Request cancellation and preserve only the turn's protocol-safe context prefix.
	 *
	 * @param int $id                   Job ID.
	 * @param int $superseded_by_job_id Optional replacement job.
	 * @return object|null
	 */
	public static function request_cancel( $id, $superseded_by_job_id = 0 ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// First publish cancellation without depending on assignment evaluation
		// order; a live worker sees this flag before its next checkpoint.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cancellation transition.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET cancel_requested = 1,
				superseded_by_job_id = CASE WHEN %d > 0 THEN %d ELSE superseded_by_job_id END,
				recovery_status = CASE WHEN status = 'uncertain' THEN 'exhausted' ELSE recovery_status END,
				updated_at = %s WHERE id = %d AND status IN ('preparing','queued','dispatching','running','uncertain')",
				self::table(),
				(int) $superseded_by_job_id,
				(int) $superseded_by_job_id,
				$now,
				(int) $id
			)
		);
		// Work that has not entered running has no billable operation in flight
		// and can be terminalized immediately.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cancellation transition.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', completed_at = %s, updated_at = %s
				WHERE id = %d AND status IN ('preparing','queued','dispatching') AND cancel_requested = 1",
				self::table(),
				$now,
				$now,
				(int) $id
			)
		);

		self::reconcile_terminal_context( $id );

		return self::get( $id );
	}

	/**
	 * Finish cooperative cancellation after a running operation returns.
	 *
	 * @param int    $id   Job ID.
	 * @param string $lock Lock token.
	 * @return bool
	 */
	public static function mark_cancelled( $id, $lock ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- terminal transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', completed_at = %s, updated_at = %s
				WHERE id = %d AND lock_token = %s AND status = 'running' AND cancel_requested = 1",
				self::table(),
				$now,
				$now,
				(int) $id,
				(string) $lock
			)
		);

		if ( 1 === $updated ) {
			self::reconcile_terminal_context( $id );
		}

		return 1 === $updated;
	}

	/**
	 * Complete a live worker job.
	 *
	 * @param int    $id   Job ID.
	 * @param string $lock Lock token.
	 * @return bool
	 */
	public static function complete( $id, $lock ) {
		return self::terminal_transition( $id, $lock, 'completed', '', '' );
	}

	/**
	 * Fail a live worker job.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function fail( $id, $lock, $code, $message ) {
		$updated = self::terminal_transition( $id, $lock, 'failed', $code, $message );
		if ( $updated ) {
			self::reconcile_terminal_context( $id );
		}

		return $updated;
	}

	/**
	 * Mark a live worker job uncertain.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function mark_uncertain( $id, $lock, $code, $message ) {
		$updated = self::terminal_transition( $id, $lock, 'uncertain', $code, $message );
		if ( $updated ) {
			self::reconcile_terminal_context( $id );
			Abilities_Bridge_Chat_Job_Runner::schedule_recovery( $id, self::RECOVERY_RETRY_SECONDS );
		}

		return $updated;
	}

	/**
	 * Reconcile an unexpected live-worker exception from durable state.
	 *
	 * Read-only/pre-provider work can resume under a fresh runner token. A
	 * provider request or data-changing ability with no durable result must
	 * remain uncertain so neither operation is repeated automatically.
	 *
	 * @param int    $id   Job ID.
	 * @param string $lock Lock token held by the failed worker.
	 * @return string unchanged|requeued|completed|uncertain|cancelled
	 */
	public static function handle_worker_exception( $id, $lock ) {
		$job = self::get( $id );
		if ( ! $job || '' === (string) $lock || ! hash_equals( (string) $job->lock_token, (string) $lock ) ) {
			return 'unchanged';
		}

		if ( 1 === (int) $job->cancel_requested ) {
			return self::mark_cancelled( $id, $lock ) ? 'cancelled' : 'unchanged';
		}

		if ( 'dispatching' === $job->status ) {
			$safe_phase = in_array( $job->phase, array( 'ready', 'tools_pending' ), true ) ? $job->phase : 'ready';

			return self::requeue_with_lock( $id, $lock, 'dispatching', $safe_phase ) ? 'requeued' : 'unchanged';
		}

		if ( 'running' !== $job->status ) {
			return 'unchanged';
		}

		if ( 'provider_inflight' === $job->phase ) {
			$persisted = self::recover_persisted_provider_message( $job );
			if ( $persisted ) {
				return $persisted;
			}

			$updated = self::mark_uncertain(
				$id,
				$lock,
				'worker_exception_ambiguous',
				__( 'The background worker stopped while an AI response was in flight. It may have completed and been billed, so it was not submitted again.', 'abilities-bridge' )
			);

			return $updated ? 'uncertain' : 'unchanged';
		}

		if ( in_array( $job->phase, array( 'ready', 'tools_pending' ), true ) ) {
			return self::requeue_with_lock( $id, $lock, 'running', $job->phase ) ? 'requeued' : 'unchanged';
		}

		if ( 'tool_inflight' === $job->phase ) {
			if ( Abilities_Bridge_Chat_Job_Steps::has_ambiguous_write( $id ) ) {
				$updated = self::mark_uncertain(
					$id,
					$lock,
					'tool_outcome_ambiguous',
					__( 'A step that changes data may have partially completed. Check the affected data before trying again.', 'abilities-bridge' )
				);

				return $updated ? 'uncertain' : 'unchanged';
			}

			if ( ! Abilities_Bridge_Chat_Job_Steps::reset_interrupted_readonly( $id ) ) {
				$updated = self::mark_uncertain(
					$id,
					$lock,
					'tool_checkpoint_recovery_failed',
					__( 'The interrupted read-only step could not be reset safely, so it was not run again.', 'abilities-bridge' )
				);

				return $updated ? 'uncertain' : 'unchanged';
			}

			return self::requeue_with_lock( $id, $lock, 'running', 'tools_pending' ) ? 'requeued' : 'unchanged';
		}

		$updated = self::mark_uncertain(
			$id,
			$lock,
			'worker_exception_ambiguous',
			__( 'The background worker stopped during an operation whose outcome could not be established safely.', 'abilities-bridge' )
		);

		return $updated ? 'uncertain' : 'unchanged';
	}

	/**
	 * Preserve a terminal job's longest protocol-valid tool history prefix.
	 *
	 * A completed write must remain visible to later model turns or the model
	 * may repeat it. Partial side-effect rounds are closed with explicit error
	 * results for every unfinished call; no tool is executed by this method.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function reconcile_terminal_context( $id ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return false;
		}

		$locked = self::acquire_conversation_lock( (int) $job->conversation_id, 2 );
		if ( ! $locked ) {
			return false;
		}

		try {
			$grouped = array();
			foreach ( Abilities_Bridge_Chat_Job_Steps::get_for_job( $id ) as $step ) {
				$grouped[ (int) $step->round_number ][] = $step;
			}
			ksort( $grouped, SORT_NUMERIC );

			$through_round = 0;
			$expected      = 1;
			foreach ( $grouped as $round_number => $steps ) {
				if ( $round_number !== $expected ) {
					break;
				}

				$fully_completed = Abilities_Bridge_Chat_Job_Steps::round_is_fully_completed( $steps );
				$has_side_effect = Abilities_Bridge_Chat_Job_Steps::round_has_side_effect_outcome( $steps );
				if ( ! $fully_completed && ! $has_side_effect ) {
					break;
				}

				$results = $fully_completed
					? Abilities_Bridge_Chat_Job_Steps::build_result_blocks( $id, $round_number )
					: Abilities_Bridge_Chat_Job_Steps::build_terminal_result_blocks( $steps );
				if ( is_wp_error( $results ) ) {
					break;
				}

				$message_id = Abilities_Bridge_Database::upsert_job_tool_result_message(
					(int) $job->conversation_id,
					(int) $id,
					(int) $round_number,
					$results
				);
				if ( is_wp_error( $message_id ) ) {
					break;
				}

				$through_round = (int) $round_number;
				++$expected;
				if ( ! $fully_completed ) {
					break;
				}
			}

			$reconciled = Abilities_Bridge_Database::set_job_context_prefix(
				(int) $id,
				(int) $job->user_message_id,
				$through_round
			);
			if (
				$reconciled
				&& Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $job->provider
				&& ! empty( $job->provider_response_id )
			) {
				Abilities_Bridge_Database::clear_last_openai_response_id(
					(int) $job->conversation_id,
					(string) $job->provider_response_id
				);
			}

			return $reconciled;
		} finally {
			self::release_conversation_lock( (int) $job->conversation_id );
		}
	}

	/**
	 * Reconcile a stale job based on its last durable phase.
	 *
	 * @param int $id Job ID.
	 * @return string Result: unchanged|requeued|completed|uncertain|failed.
	 */
	public static function sweep( $id ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return 'unchanged';
		}

		$now = time();
		if ( 'preparing' === $job->status && strtotime( $job->created_at . ' UTC' ) < $now - self::PREPARING_STALE_SECONDS ) {
			self::cleanup_preparing_artifacts( $job );
			self::fail_without_lock( $id, 'enqueue_abandoned', __( 'The chat request stopped before it could be queued.', 'abilities-bridge' ), array( 'preparing' ) );
			return 'failed';
		}

		if ( 'dispatching' === $job->status && ! empty( $job->heartbeat_at ) && strtotime( $job->heartbeat_at . ' UTC' ) < $now - self::DISPATCH_STALE_SECONDS ) {
			$safe_phase = in_array( $job->phase, array( 'ready', 'tools_pending' ), true ) ? $job->phase : 'ready';

			return self::requeue_without_lock( $id, array( 'dispatching' ), $safe_phase ) ? 'requeued' : 'unchanged';
		}

		if ( 'running' !== $job->status || empty( $job->lease_expires_at ) || strtotime( $job->lease_expires_at . ' UTC' ) >= $now ) {
			return 'unchanged';
		}

		if ( 1 === (int) $job->cancel_requested ) {
			self::cancel_without_lock( $id );
			return 'unchanged';
		}

		if ( 'provider_inflight' === $job->phase ) {
			$persisted = self::recover_persisted_provider_message( $job );
			if ( $persisted ) {
				return $persisted;
			}
		}

		if ( in_array( $job->phase, array( 'ready', 'tools_pending' ), true ) ) {
			return self::requeue_without_lock( $id, array( 'running' ), $job->phase ) ? 'requeued' : 'unchanged';
		}

		if ( 'tool_inflight' === $job->phase ) {
			if ( Abilities_Bridge_Chat_Job_Steps::has_ambiguous_write( $id ) ) {
				self::uncertain_without_lock( $id, 'tool_outcome_ambiguous', __( 'A step that changes data may have partially completed. Check the affected data before trying again.', 'abilities-bridge' ) );
				return 'uncertain';
			}

			Abilities_Bridge_Chat_Job_Steps::reset_interrupted_readonly( $id );
			return self::requeue_without_lock( $id, array( 'running' ), 'tools_pending' ) ? 'requeued' : 'unchanged';
		}

		self::uncertain_without_lock( $id, 'ai_timeout_ambiguous', __( 'The AI request stopped while a provider response was in flight. It may have completed and been billed, so it was not submitted again.', 'abilities-bridge' ) );

		return 'uncertain';
	}

	/**
	 * Find the latest active, uncertain, or recently completed job.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         User ID.
	 * @return object|null
	 */
	public static function find_resumable( $conversation_id, $user_id ) {
		global $wpdb;

		$uncertain_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS );
		$complete_cutoff  = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- resume lookup.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE conversation_id = %d AND user_id = %d AND
				( (status IN ('preparing','queued','dispatching','running') AND cancel_requested = 0)
				OR (status = 'uncertain' AND cancel_requested = 0 AND created_at >= %s)
				OR (status = 'completed' AND completed_at >= %s) )
				ORDER BY id DESC LIMIT 1",
				self::table(),
				(int) $conversation_id,
				(int) $user_id,
				$uncertain_cutoff,
				$complete_cutoff
			)
		);
	}

	/**
	 * Find the newest live or uncertain job for bare admin-page reattachment.
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function find_latest_resumable_for_user( $user_id ) {
		global $wpdb;

		$uncertain_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- page-load resume lookup.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE user_id = %d AND cancel_requested = 0
				AND (status IN ('preparing','queued','dispatching','running')
				OR (status = 'uncertain' AND created_at >= %s))
				ORDER BY id DESC LIMIT 1",
				self::table(),
				(int) $user_id,
				$uncertain_cutoff
			)
		);
	}

	/**
	 * Atomically claim an overdue OpenAI recovery attempt.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function begin_recovery( $id ) {
		global $wpdb;
		$retry_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECOVERY_RETRY_SECONDS );
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECOVERY_STALE_SECONDS );
		$age_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recovery gate.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET recovery_status = 'recovering', updated_at = %s
				WHERE id = %d AND status = 'uncertain' AND provider = 'openai'
				AND phase = 'provider_inflight'
				AND provider_response_id <> '' AND cancel_requested = 0 AND created_at >= %s
				AND ( ( recovery_status = '' AND updated_at <= %s )
					OR ( recovery_status = 'recovering' AND updated_at <= %s ) )",
				self::table(),
				current_time( 'mysql', true ),
				(int) $id,
				$age_cutoff,
				$retry_cutoff,
				$stale_cutoff
			)
		);

		return 1 === $updated;
	}

	/**
	 * Mark recovery exhausted or release the gate for a still-pending response.
	 *
	 * @param int  $id        Job ID.
	 * @param bool $exhausted Whether the provider result is definitively unavailable.
	 * @return bool
	 */
	public static function finish_recovery_attempt( $id, $exhausted ) {
		global $wpdb;

		$status = $exhausted ? 'exhausted' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- recovery gate.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET recovery_status = %s, updated_at = %s
				WHERE id = %d AND status = 'uncertain' AND recovery_status = 'recovering'",
				self::table(),
				$status,
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Whether an uncertain OpenAI response remains eligible for GET recovery.
	 *
	 * @param object $job Job row.
	 * @return bool
	 */
	public static function is_openai_recovery_pending( $job ) {
		if ( ! $job || 'uncertain' !== $job->status || 'openai' !== $job->provider || 'provider_inflight' !== $job->phase ) {
			return false;
		}

		if ( empty( $job->provider_response_id ) || (int) $job->cancel_requested || 'exhausted' === $job->recovery_status ) {
			return false;
		}

		return self::age( $job ) < self::UNCERTAIN_RESUME_HOURS * HOUR_IN_SECONDS;
	}

	/**
	 * Close recovery after its deadline or a definitive provider result.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function mark_recovery_exhausted( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic recovery terminal state.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET recovery_status = 'exhausted', updated_at = %s
				WHERE id = %d AND status = 'uncertain' AND recovery_status <> 'exhausted'",
				self::table(),
				current_time( 'mysql', true ),
				(int) $id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Finalize a durably ingested OpenAI recovery payload.
	 *
	 * @param int  $id        Job ID.
	 * @param bool $has_tools Whether the payload contains tool calls.
	 * @return bool
	 */
	public static function finish_recovered_response( $id, $has_tools ) {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$status = $has_tools ? 'queued' : 'completed';
		$phase  = $has_tools ? 'tools_pending' : 'ready';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic recovery ingestion.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, phase = %s, rounds_completed = rounds_completed + 1,
				recovery_status = %s, runner_token = %s, lock_token = %s, last_dispatched_at = NULL,
				lease_expires_at = NULL, heartbeat_at = NULL, error_code = %s, error_message = %s,
				completed_at = CASE WHEN %d = 1 THEN %s ELSE NULL END, updated_at = %s
				WHERE id = %d AND status = %s AND recovery_status = %s AND cancel_requested = 0',
				self::table(),
				$status,
				$phase,
				'',
				self::generate_token(),
				'',
				'',
				'',
				$has_tools ? 0 : 1,
				$now,
				$now,
				(int) $id,
				'uncertain',
				'recovering'
			)
		);

		return 1 === $updated;
	}

	/**
	 * Purge old terminal and abandoned rows.
	 *
	 * @return int Number of deleted jobs.
	 */
	public static function purge_old() {
		global $wpdb;

		$terminal_cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::TERMINAL_RETENTION_DAYS * DAY_IN_SECONDS );
		$abandoned_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// Reconcile very old expired workers before selecting deletable rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup.
		$stale_running = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'running' AND lease_expires_at < %s AND updated_at < %s",
				self::table(),
				current_time( 'mysql', true ),
				$abandoned_cutoff
			)
		);
		foreach ( $stale_running as $stale_job ) {
			if ( (int) $stale_job->cancel_requested ) {
				self::cancel_without_lock( (int) $stale_job->id );
			} elseif ( 'tool_inflight' === $stale_job->phase && Abilities_Bridge_Chat_Job_Steps::has_ambiguous_write( $stale_job->id ) ) {
				self::uncertain_without_lock( $stale_job->id, 'tool_outcome_ambiguous', __( 'A step that changes data may have partially completed. Check the affected data before trying again.', 'abilities-bridge' ) );
			} else {
				self::uncertain_without_lock( $stale_job->id, 'job_abandoned', __( 'The background worker was inactive for more than a day. Its last in-flight step was not restarted automatically.', 'abilities-bridge' ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup.
		$old_jobs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE
				(status IN ('completed','failed','cancelled','uncertain') AND completed_at < %s)
				OR (status IN ('preparing','queued','dispatching') AND created_at < %s)",
				self::table(),
				$terminal_cutoff,
				$abandoned_cutoff
			)
		);

		if ( empty( $old_jobs ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( $old_jobs as $old_job ) {
			$job_id = (int) $old_job->id;
			if ( 'preparing' === $old_job->status ) {
				self::cleanup_preparing_artifacts( $old_job );
			} elseif ( in_array( $old_job->status, array( 'queued', 'dispatching', 'failed', 'cancelled', 'uncertain' ), true ) ) {
				// Keep the ledger until its provider-visible transcript is durable.
				if ( ! self::reconcile_terminal_context( $job_id ) ) {
					continue;
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup.
			$wpdb->delete(
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOB_STEPS ),
				array( 'job_id' => $job_id ),
				array( '%d' )
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lifecycle cleanup.
			$result = $wpdb->delete( self::table(), array( 'id' => $job_id ), array( '%d' ) );
			if ( $result ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Job age in seconds.
	 *
	 * @param object $job Job row.
	 * @return int
	 */
	public static function age( $job ) {
		return max( 0, time() - strtotime( $job->created_at . ' UTC' ) );
	}

	/**
	 * Terminal worker transition guarded by the lock and cancellation flag.
	 *
	 * @param int    $id      Job ID.
	 * @param string $lock    Lock token.
	 * @param string $status  Terminal status.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return bool
	 */
	private static function terminal_transition( $id, $lock, $status, $code, $message ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- terminal transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, error_code = %s, error_message = %s,
				completed_at = %s, updated_at = %s WHERE id = %d AND lock_token = %s
				AND status = %s AND cancel_requested = 0',
				self::table(),
				$status,
				sanitize_key( $code ),
				(string) $message,
				$now,
				$now,
				(int) $id,
				(string) $lock,
				'running'
			)
		);

		return 1 === $updated;
	}

	/**
	 * Requeue a stale job without trusting the dead worker's lock.
	 *
	 * @param int    $id            Job ID.
	 * @param array  $from_statuses Allowed source statuses.
	 * @param string $phase         Safe resume phase.
	 * @return bool
	 */
	private static function requeue_without_lock( $id, $from_statuses, $phase ) {
		global $wpdb;

		$from_status = reset( $from_statuses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stale recovery transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'queued', phase = %s, runner_token = %s, lock_token = '',
				lease_expires_at = NULL, heartbeat_at = NULL, last_dispatched_at = NULL, updated_at = %s
				WHERE id = %d AND status = %s AND cancel_requested = 0",
				self::table(),
				$phase,
				self::generate_token(),
				current_time( 'mysql', true ),
				(int) $id,
				$from_status
			)
		);

		return 1 === $updated;
	}

	/**
	 * Requeue a failed live worker while it still owns the row lock.
	 *
	 * @param int    $id          Job ID.
	 * @param string $lock        Failed worker lock token.
	 * @param string $from_status Current status.
	 * @param string $phase       Safe resume phase.
	 * @return bool
	 */
	private static function requeue_with_lock( $id, $lock, $from_status, $phase ) {
		global $wpdb;

		if ( ! in_array( $from_status, array( 'dispatching', 'running' ), true ) || ! in_array( $phase, array( 'ready', 'tools_pending' ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live-worker recovery transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'queued', phase = %s, runner_token = %s, lock_token = '',
					lease_expires_at = NULL, heartbeat_at = NULL, last_dispatched_at = NULL,
					error_code = '', error_message = '', completed_at = NULL, recovery_status = '', updated_at = %s
					WHERE id = %d AND lock_token = %s AND status = %s AND cancel_requested = 0",
				self::table(),
				$phase,
				self::generate_token(),
				current_time( 'mysql', true ),
				(int) $id,
				(string) $lock,
				$from_status
			)
		);

		return 1 === $updated;
	}

	/**
	 * Mark stale provider/tool work uncertain without a live lock.
	 *
	 * @param int    $id      Job ID.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return bool
	 */
	private static function uncertain_without_lock( $id, $code, $message ) {
		$updated = self::fail_without_lock( $id, $code, $message, array( 'running' ), 'uncertain' );
		if ( $updated ) {
			self::reconcile_terminal_context( $id );
			Abilities_Bridge_Chat_Job_Runner::schedule_recovery( $id, self::RECOVERY_RETRY_SECONDS );
		}

		return $updated;
	}

	/**
	 * Mark a stale row terminal without a worker lock.
	 *
	 * @param int    $id            Job ID.
	 * @param string $code          Error code.
	 * @param string $message       Error message.
	 * @param array  $from_statuses Source statuses.
	 * @param string $target        Target status.
	 * @return bool
	 */
	private static function fail_without_lock( $id, $code, $message, $from_statuses, $target = 'failed' ) {
		global $wpdb;

		$now         = current_time( 'mysql', true );
		$from_status = reset( $from_statuses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- stale terminal transition.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, error_code = %s, error_message = %s,
				completed_at = %s, updated_at = %s WHERE id = %d AND status = %s',
				self::table(),
				$target,
				sanitize_key( $code ),
				(string) $message,
				$now,
				$now,
				(int) $id,
				$from_status
			)
		);

		return 1 === $updated;
	}

	/**
	 * Terminally cancel a stale running row.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	private static function cancel_without_lock( $id ) {
		$updated = self::fail_without_lock( $id, '', '', array( 'running' ), 'cancelled' );
		if ( $updated ) {
			self::reconcile_terminal_context( $id );
		}

		return $updated;
	}

	/**
	 * Remove message/file artifacts left by a request that died while preparing.
	 *
	 * @param object $job Preparing job.
	 * @return void
	 */
	private static function cleanup_preparing_artifacts( $job ) {
		global $wpdb;

		$blocks = json_decode( (string) $job->attachment_json, true );
		if ( is_array( $blocks ) && class_exists( 'Abilities_Bridge_Attachments' ) ) {
			Abilities_Bridge_Attachments::delete_attachment_blocks( $job->conversation_id, $blocks );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- abandoned enqueue rollback.
		$wpdb->delete(
			Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MESSAGES ),
			array( 'job_id' => (int) $job->id ),
			array( '%d' )
		);
		wp_cache_delete( 'abilities_bridge_conv_msgs_' . (int) $job->conversation_id, 'abilities_bridge' );
	}

	/**
	 * Recover the narrow death window after an assistant message insert but
	 * before the job's provider-round checkpoint UPDATE.
	 *
	 * @param object $job Expired running job.
	 * @return string|false completed, requeued, or false when no checkpoint exists.
	 */
	private static function recover_persisted_provider_message( $job ) {
		global $wpdb;

		$next_round = (int) $job->rounds_completed + 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- crash-window recovery lookup.
		$message = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE job_id = %d AND job_round = %d AND role = 'assistant'
				AND context_visible = 1 ORDER BY id DESC LIMIT 1",
				Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_MESSAGES ),
				(int) $job->id,
				$next_round
			)
		);
		if ( ! $message ) {
			return false;
		}

		$content = json_decode( $message->content, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $content ) ) {
			return false;
		}

		$tool_uses = array();
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
				$tool_uses[] = $block;
			}
		}
		if ( ! empty( $tool_uses ) ) {
			$seeded = Abilities_Bridge_Chat_Job_Steps::seed( $job->id, $next_round, $tool_uses );
			if ( is_wp_error( $seeded ) ) {
				return false;
			}
		}

		$now        = current_time( 'mysql', true );
		$has_tools  = ! empty( $tool_uses );
		$new_status = $has_tools ? 'queued' : 'completed';
		$new_phase  = $has_tools ? 'tools_pending' : 'ready';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic crash-window recovery.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, phase = %s, rounds_completed = rounds_completed + 1,
				runner_token = %s, lock_token = '', heartbeat_at = NULL, lease_expires_at = NULL,
				last_dispatched_at = NULL, completed_at = CASE WHEN %d = 1 THEN %s ELSE NULL END,
				updated_at = %s WHERE id = %d AND status = 'running' AND phase = 'provider_inflight'
				AND rounds_completed = %d AND cancel_requested = 0",
				self::table(),
				$new_status,
				$new_phase,
				self::generate_token(),
				$has_tools ? 0 : 1,
				$now,
				$now,
				(int) $job->id,
				(int) $job->rounds_completed
			)
		);
		if ( 1 !== $updated ) {
			return false;
		}

		if ( Abilities_Bridge_AI_Provider::PROVIDER_OPENAI === $job->provider && ! empty( $job->provider_response_id ) ) {
			Abilities_Bridge_Database::update_last_openai_response_id( $job->conversation_id, $job->provider_response_id );
		}

		return $has_tools ? 'requeued' : 'completed';
	}

	/**
	 * Jobs table name.
	 *
	 * @return string
	 */
	private static function table() {
		return Abilities_Bridge_Database::table( Abilities_Bridge_Database::TABLE_CHAT_JOBS );
	}

	/**
	 * Generate a 256-bit token.
	 *
	 * @return string
	 */
	private static function generate_token() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			return hash( 'sha256', wp_generate_password( 64, true, true ) . microtime( true ) );
		}
	}
}
