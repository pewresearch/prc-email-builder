<?php
declare(strict_types=1);
/**
 * Recurring dispatcher for scheduled email automations.
 *
 * Design (see the PRC-456 plan): rather than one Action Scheduler job per
 * enrollment per step, a single recurring action fires every 15 minutes and
 * processes every enrollment whose computed `due_at` has passed. Due rows are
 * grouped by render key (follow-up post + context hash) so identical-render
 * recipients batch into a single {@see System_Email_Sender::send_many()} call.
 *
 * Enrollment creation hangs off the `prc_email_builder_system_email_sent`
 * action, so every send path (REST, form action, batch) enrolls automatically
 * when the trigger email defines follow-up steps.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

class Automation_Scheduler {

	const DISPATCH_HOOK = 'prc_email_automation_process_due';
	const ACTION_GROUP  = 'prc-email-builder-automations';

	/**
	 * Object-cache mutex ensuring only one dispatcher run touches due rows at a
	 * time. `get_due()` is a plain SELECT with no per-row claim, so overlapping
	 * runs (recurring action + manual CLI, or parallel workers) could otherwise
	 * read and send the same enrollments before either advances them.
	 */
	const LOCK_KEY   = 'dispatch_lock';
	const LOCK_GROUP = 'prc_email_automations';
	const LOCK_TTL   = 5 * MINUTE_IN_SECONDS;

	/**
	 * Dispatcher cadence. 15 minutes bounds worst-case latency after a step's
	 * send window opens while supporting mixed per-step windows without N jobs.
	 */
	const INTERVAL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Max enrollments processed per dispatcher run (backpressure). Overflow is
	 * picked up by the next run since due_at stays in the past.
	 */
	const BATCH_CAP = 500;

	/**
	 * Re-entrancy guard: while the dispatcher is sending follow-ups it must not
	 * auto-enroll off its own `system_email_sent` firings (that would loop /
	 * double-enroll). Steps within a chain are advanced explicitly instead.
	 */
	private static bool $dispatching = false;

	/**
	 * Owner token for the dispatcher mutex held by the current run. Scopes
	 * release/refresh so a run only ever touches the lock it still owns — if
	 * the TTL lapsed and another dispatcher reclaimed the key, this run must
	 * neither delete nor extend that new owner's lock.
	 */
	private static string $lock_token = '';

	/**
	 * Register hooks. Call once during plugin bootstrap.
	 */
	public static function init(): void {
		add_action( self::DISPATCH_HOOK, [ __CLASS__, 'process_due' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );

		// Enroll a recipient after a trigger email is successfully sent.
		add_action( 'prc_email_builder_system_email_sent', [ __CLASS__, 'on_system_email_sent' ], 10, 3 );

		// Cancel in-flight enrollments whose follow-up template is no longer valid.
		add_action( 'transition_post_status', [ __CLASS__, 'on_post_status_transition' ], 10, 3 );
	}

	/**
	 * Ensure the recurring dispatcher is scheduled.
	 *
	 * @hook init
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::DISPATCH_HOOK, [], self::ACTION_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL,
			self::INTERVAL,
			self::DISPATCH_HOOK,
			[],
			self::ACTION_GROUP
		);
	}

	/**
	 * Enrollment creation listener.
	 *
	 * @param int                  $post_id  Trigger email post ID.
	 * @param string               $to_email Recipient address.
	 * @param array<string, mixed> $context  Merge context used for the send.
	 */
	public static function on_system_email_sent( $post_id, $to_email, $context = [] ): void {
		if ( self::$dispatching ) {
			return;
		}

		$post_id  = (int) $post_id;
		$to_email = (string) $to_email;
		$context  = is_array( $context ) ? $context : [];

		if ( $post_id <= 0 || ! is_email( $to_email ) ) {
			return;
		}

		$config = Automation_Config::get( $post_id );
		if ( empty( $config['steps'] ) ) {
			return;
		}

		$source = [
			'trigger_post_id' => $post_id,
			'system_key'      => (string) get_post_field( 'post_name', $post_id ),
		];

		Automation_Enrollment::enroll( $post_id, $to_email, $context, $config, $source );
	}

	/**
	 * Process all due enrollments: group by render key, send, advance/log.
	 *
	 * @hook self::DISPATCH_HOOK
	 * @param int $limit Optional override for the per-run cap (used by CLI).
	 * @return array{due:int, sent:int, failed:int, groups:int} Run summary.
	 */
	public static function process_due( int $limit = 0 ): array {
		$summary = [ 'due' => 0, 'sent' => 0, 'failed' => 0, 'groups' => 0 ];

		// Claim the dispatcher lock so a concurrent run can't read and send the
		// same due rows before we advance them. If another run holds it, bail.
		if ( ! self::acquire_lock() ) {
			return $summary;
		}

		try {
			$limit = $limit > 0 ? $limit : self::BATCH_CAP;
			$rows  = Automation_Enrollment::get_due( $limit );
			if ( empty( $rows ) ) {
				return $summary;
			}
			$summary['due'] = count( $rows );

			// Group by follow-up post + context hash so identical renders batch.
			$groups = [];
			foreach ( $rows as $row ) {
				$render_key            = (int) $row['follow_up_post_id'] . ':' . (string) $row['context_hash'];
				$groups[ $render_key ] = $groups[ $render_key ] ?? [];
				$groups[ $render_key ][] = $row;
			}
			$summary['groups'] = count( $groups );

			self::$dispatching = true;
			try {
				foreach ( $groups as $group_rows ) {
					// Fencing: if the lock lapsed mid-run and another dispatcher
					// reclaimed it, stop now so we never send groups the new owner
					// is responsible for and cause duplicate follow-ups.
					if ( ! self::owns_lock() ) {
						break;
					}
					$result = self::process_group( $group_rows );
					$summary['sent']   += $result['sent'];
					$summary['failed'] += $result['failed'];

					// Heartbeat: extend the TTL after each group so a long batch
					// keeps the lock fresh instead of expiring mid-run.
					self::refresh_lock();
				}
			} finally {
				self::$dispatching = false;
			}

			return $summary;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Acquire the single-dispatcher mutex. `wp_cache_add()` is atomic on a
	 * persistent object cache (Memcached on VIP), so only the first caller wins;
	 * the TTL guarantees a crashed run cannot wedge the dispatcher permanently.
	 * The stored value is a per-run owner token so release/refresh can prove
	 * ownership and never touch a lock a later run legitimately reclaimed.
	 *
	 * @return bool True when this run holds the lock.
	 */
	private static function acquire_lock(): bool {
		$token = uniqid( '', true );
		if ( ! wp_cache_add( self::LOCK_KEY, $token, self::LOCK_GROUP, self::LOCK_TTL ) ) {
			return false;
		}
		self::$lock_token = $token;
		return true;
	}

	/**
	 * Whether this run still owns the dispatcher mutex.
	 *
	 * @return bool
	 */
	private static function owns_lock(): bool {
		if ( '' === self::$lock_token ) {
			return false;
		}
		return (string) wp_cache_get( self::LOCK_KEY, self::LOCK_GROUP ) === self::$lock_token;
	}

	/**
	 * Heartbeat the mutex: reset its TTL, but only while we still own it, so a
	 * long dispatch keeps the lock fresh instead of expiring mid-run.
	 */
	private static function refresh_lock(): void {
		if ( ! self::owns_lock() ) {
			return;
		}
		wp_cache_set( self::LOCK_KEY, self::$lock_token, self::LOCK_GROUP, self::LOCK_TTL );
	}

	/**
	 * Release the single-dispatcher mutex so the next run can proceed promptly,
	 * but only if we still own it — otherwise we would delete a lock a later
	 * run legitimately reclaimed after ours expired.
	 */
	private static function release_lock(): void {
		if ( self::owns_lock() ) {
			wp_cache_delete( self::LOCK_KEY, self::LOCK_GROUP );
		}
		self::$lock_token = '';
	}

	/**
	 * Send one render-key group and update each enrollment.
	 *
	 * @param array<int, array<string, mixed>> $rows Enrollment rows sharing a render key.
	 * @return array{sent:int, failed:int}
	 */
	private static function process_group( array $rows ): array {
		$out = [ 'sent' => 0, 'failed' => 0 ];

		$follow_up_post_id = (int) ( $rows[0]['follow_up_post_id'] ?? 0 );

		if ( ! Automation_Config::is_eligible_follow_up( $follow_up_post_id ) ) {
			foreach ( $rows as $row ) {
				Automation_Enrollment::cancel( (int) $row['id'] );
				$out['failed']++;
			}
			return $out;
		}

		// Context is frozen at enrollment; identical across the group by hash.
		$context = Automation_Enrollment::decode_json_array( $rows[0]['context_json'] ?? '' );

		/**
		 * Filter the merge context for an automation follow-up send. Default is
		 * the frozen context; return a fresh context to re-resolve block bits.
		 *
		 * @param array<string, mixed> $context           Frozen merge context.
		 * @param int                  $follow_up_post_id Follow-up email post ID.
		 * @param array<int, array<string, mixed>> $rows  Enrollment rows in this group.
		 */
		$context = (array) apply_filters(
			'prc_email_builder_automation_context',
			$context,
			$follow_up_post_id,
			$rows
		);

		// One address can carry several rows here (e.g. distinct triggers whose
		// follow-up + frozen context collapse to the same render key), so bucket
		// every row per email and apply the send outcome to all of its siblings —
		// otherwise the batched send deduplicates the address but only one row is
		// advanced, leaving the rest due to fire again.
		$by_email = [];
		foreach ( $rows as $row ) {
			$by_email[ strtolower( (string) $row['recipient_email'] ) ][] = $row;
		}
		$emails = array_keys( $by_email );

		if ( count( $emails ) > 1 ) {
			$result = System_Email_Sender::send_many( $follow_up_post_id, $emails, $context );

			if ( is_wp_error( $result ) ) {
				// Whole-batch failure: retry every row next run.
				foreach ( $rows as $row ) {
					Automation_Enrollment::record_failure( $row, $result->get_error_message() );
					$out['failed']++;
				}
				return $out;
			}

			foreach ( $result['sent'] as $email ) {
				foreach ( $by_email[ strtolower( $email ) ] ?? [] as $row ) {
					self::advance_row( $row, [ 'mandrill_status' => 'sent' ] );
					$out['sent']++;
				}
			}
			foreach ( $result['failed'] as $email => $reason ) {
				foreach ( $by_email[ strtolower( (string) $email ) ] ?? [] as $row ) {
					Automation_Enrollment::record_failure( $row, (string) $reason );
					$out['failed']++;
				}
			}

			return $out;
		}

		// Single recipient: use the per-recipient send path.
		$email_rows = $by_email[ $emails[0] ];
		$result     = System_Email_Sender::send( $follow_up_post_id, (string) $email_rows[0]['recipient_email'], $context );
		if ( is_wp_error( $result ) ) {
			foreach ( $email_rows as $row ) {
				Automation_Enrollment::record_failure( $row, $result->get_error_message() );
				$out['failed']++;
			}
		} else {
			foreach ( $email_rows as $row ) {
				self::advance_row( $row, [ 'mandrill_status' => 'sent' ] );
				$out['sent']++;
			}
		}

		return $out;
	}

	/**
	 * Advance an enrollment row using the trigger's current config.
	 *
	 * @param array<string, mixed> $row       Enrollment row.
	 * @param array<string, mixed> $log_entry Outcome to log.
	 */
	private static function advance_row( array $row, array $log_entry ): void {
		$config = Automation_Config::get( (int) $row['trigger_post_id'] );
		$window = Automation_Enrollment::decode_json_array( $row['send_window_snapshot'] ?? '' );
		if ( ! empty( $window ) ) {
			$log_entry['resolved_send_window'] = $window;
		}
		Automation_Enrollment::advance( $row, $config, $log_entry );
	}

	/**
	 * Cancel in-flight enrollments when a follow-up template is unpublished.
	 *
	 * @hook transition_post_status
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post being transitioned.
	 */
	public static function on_post_status_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || 'publish' === $new_status ) {
			return;
		}
		if ( 'publish' !== $old_status || ! Post_Type::is_transactional_post( $post ) ) {
			return;
		}

		self::cancel_enrollments_for_follow_up( (int) $post->ID );
	}

	/**
	 * Cancel every active enrollment currently pointed at a follow-up post.
	 *
	 * @param int $follow_up_post_id Follow-up email post ID.
	 * @return int Rows cancelled.
	 */
	public static function cancel_enrollments_for_follow_up( int $follow_up_post_id ): int {
		global $wpdb;

		if ( $follow_up_post_id <= 0 || ! Automation_Enrollment::table_exists() ) {
			return 0;
		}

		$table = Automation_Enrollment::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, due_at = NULL, updated_at = %s WHERE follow_up_post_id = %d AND status = %s",
				Automation_Enrollment::STATUS_CANCELLED,
				current_time( 'mysql', true ),
				$follow_up_post_id,
				Automation_Enrollment::STATUS_ACTIVE
			)
		);

		return (int) $updated;
	}
}
