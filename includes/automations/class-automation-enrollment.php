<?php
declare(strict_types=1);
/**
 * Enrollment store for scheduled email automations.
 *
 * Each row is a single recipient's progress through a trigger email's follow-up
 * chain. The recurring dispatcher ({@see Automation_Scheduler}) drives sends off
 * the computed `due_at` column rather than one Action Scheduler job per step, so
 * recipients enrolled hours apart on the same day batch together in the step's
 * send window.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Manages the prc_email_automation_enrollments table and its lifecycle queries.
 */
class Automation_Enrollment {

	const TABLE_NAME     = 'prc_email_automation_enrollments';
	const DB_VERSION_KEY = 'prc_email_automation_enrollments_db_version';
	const DB_VERSION     = '1.0.0';

	const STATUS_ACTIVE    = 'active';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Max consecutive send failures for a step before the enrollment is parked
	 * as `cancelled` (dead-letter) so it stops consuming dispatcher cycles.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Full table name including the WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Whether the enrollments table exists.
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $result === $table;
	}

	/**
	 * Create or upgrade the enrollments table.
	 */
	public static function create_table(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trigger_post_id bigint(20) unsigned NOT NULL,
			recipient_email varchar(190) NOT NULL,
			context_json longtext NULL,
			context_hash char(40) NOT NULL DEFAULT '',
			send_window_snapshot varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'active',
			current_step int(10) unsigned NOT NULL DEFAULT 0,
			follow_up_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			due_at datetime NULL,
			initial_sent_at datetime NOT NULL,
			last_step_sent_at datetime NULL,
			source longtext NULL,
			step_log longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_due (status,due_at),
			KEY dispatch (follow_up_post_id,due_at,context_hash),
			KEY trigger_recipient (trigger_post_id,recipient_email)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );

		return self::table_exists();
	}

	/**
	 * Ensure the table exists when the stored schema version is stale.
	 */
	public static function maybe_create_table(): bool {
		$db_version = get_option( self::DB_VERSION_KEY, '' );

		if ( self::DB_VERSION !== $db_version || ! self::table_exists() ) {
			return self::create_table();
		}

		return true;
	}

	/**
	 * Stable hash of a merge context, used to group identical-render recipients.
	 *
	 * @param array<string, mixed> $context Merge context.
	 */
	public static function context_hash( array $context ): string {
		ksort( $context );
		return sha1( (string) wp_json_encode( $context ) );
	}

	/**
	 * Create an enrollment for a recipient of a trigger email and schedule its
	 * first follow-up step. Any prior active enrollment for the same recipient +
	 * trigger is cancelled first (re-enrollment supersedes).
	 *
	 * @param int                  $trigger_post_id Trigger email post ID.
	 * @param string               $recipient_email Recipient address (validated).
	 * @param array<string, mixed> $context         Frozen merge context.
	 * @param array<string, mixed> $config          Sanitized automation config.
	 * @param array<string, mixed> $source          Optional source metadata (form id, key…).
	 * @return int|null New enrollment ID, or null when nothing was scheduled.
	 */
	public static function enroll(
		int $trigger_post_id,
		string $recipient_email,
		array $context,
		array $config,
		array $source = []
	): ?int {
		global $wpdb;

		self::maybe_create_table();

		$recipient_email = strtolower( trim( $recipient_email ) );
		$steps           = is_array( $config['steps'] ?? null ) ? $config['steps'] : [];

		if ( $trigger_post_id <= 0 || ! is_email( $recipient_email ) || empty( $steps ) ) {
			return null;
		}

		// Re-enrollment supersedes: cancel prior active runs for this pair.
		self::cancel_active( $trigger_post_id, $recipient_email );

		$now    = current_time( 'mysql', true );
		$window = Automation_Window::resolve( $steps[0], $config );
		$due_at = Automation_Window::compute_due_at( $now, (int) ( $steps[0]['delay_days'] ?? 0 ), $window );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			self::table_name(),
			[
				'trigger_post_id'      => $trigger_post_id,
				'recipient_email'      => $recipient_email,
				'context_json'         => (string) wp_json_encode( $context ),
				'context_hash'         => self::context_hash( $context ),
				'send_window_snapshot' => (string) wp_json_encode( $window ),
				'status'               => self::STATUS_ACTIVE,
				'current_step'         => 0,
				'follow_up_post_id'    => (int) ( $steps[0]['follow_up_post_id'] ?? 0 ),
				'attempts'             => 0,
				'due_at'               => $due_at,
				'initial_sent_at'      => $now,
				'last_step_sent_at'    => null,
				'source'               => empty( $source ) ? null : (string) wp_json_encode( $source ),
				'step_log'             => (string) wp_json_encode( [] ),
				'created_at'           => $now,
				'updated_at'           => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Fetch active enrollments whose due_at has passed, oldest first.
	 *
	 * @param int $limit Max rows (dispatcher backpressure cap).
	 * @return array<int, array<string, mixed>> Raw rows.
	 */
	public static function get_due( int $limit = 500 ): array {
		global $wpdb;

		self::maybe_create_table();
		if ( ! self::table_exists() ) {
			return [];
		}

		$limit = max( 1, $limit );
		$now   = current_time( 'mysql', true );
		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND due_at IS NOT NULL AND due_at <= %s ORDER BY due_at ASC LIMIT %d",
				self::STATUS_ACTIVE,
				$now,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Advance an enrollment after a successful step send: append the step log,
	 * move to the next step (recomputing window + due_at) or mark completed.
	 *
	 * @param array<string, mixed> $row          Current enrollment row.
	 * @param array<string, mixed> $config       Trigger's current automation config.
	 * @param array<string, mixed> $log_entry    Outcome to append to step_log.
	 */
	public static function advance( array $row, array $config, array $log_entry ): void {
		global $wpdb;

		$id           = (int) $row['id'];
		$current_step = (int) $row['current_step'];
		$steps        = is_array( $config['steps'] ?? null ) ? $config['steps'] : [];
		$now          = current_time( 'mysql', true );

		$log   = self::decode_json_array( $row['step_log'] ?? '' );
		$log[] = array_merge( [ 'step' => $current_step, 'sent_at' => $now ], $log_entry );

		$next_step = $current_step + 1;

		$data    = [
			'last_step_sent_at' => $now,
			'attempts'          => 0,
			'step_log'          => (string) wp_json_encode( $log ),
			'updated_at'        => $now,
		];
		$formats = [ '%s', '%d', '%s', '%s' ];

		if ( isset( $steps[ $next_step ] ) ) {
			$window = Automation_Window::resolve( $steps[ $next_step ], $config );
			$due_at = Automation_Window::compute_due_at( $now, (int) ( $steps[ $next_step ]['delay_days'] ?? 0 ), $window );

			$data['current_step']         = $next_step;
			$data['follow_up_post_id']    = (int) ( $steps[ $next_step ]['follow_up_post_id'] ?? 0 );
			$data['send_window_snapshot'] = (string) wp_json_encode( $window );
			$data['due_at']               = $due_at;
			$formats                      = array_merge( $formats, [ '%d', '%d', '%s', '%s' ] );
		} else {
			$data['status'] = self::STATUS_COMPLETED;
			$data['due_at'] = null;
			$formats        = array_merge( $formats, [ '%s', '%s' ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table_name(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * Record a failed step send. Leaves due_at unchanged so the next dispatcher
	 * run retries, until MAX_ATTEMPTS is hit — then the row is dead-lettered.
	 *
	 * @param array<string, mixed> $row       Current enrollment row.
	 * @param string               $error     Failure reason.
	 */
	public static function record_failure( array $row, string $error ): void {
		global $wpdb;

		$id       = (int) $row['id'];
		$attempts = (int) $row['attempts'] + 1;
		$now      = current_time( 'mysql', true );

		$log   = self::decode_json_array( $row['step_log'] ?? '' );
		$log[] = [
			'step'     => (int) $row['current_step'],
			'failed_at' => $now,
			'error'    => $error,
			'attempt'  => $attempts,
		];

		$data = [
			'attempts'   => $attempts,
			'step_log'   => (string) wp_json_encode( $log ),
			'updated_at' => $now,
		];
		$formats = [ '%d', '%s', '%s' ];

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$data['status'] = self::STATUS_CANCELLED;
			$data['due_at'] = null;
			$formats        = array_merge( $formats, [ '%s', '%s' ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( self::table_name(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
	}

	/**
	 * Cancel a single enrollment by ID.
	 *
	 * @return bool True when a row was updated.
	 */
	public static function cancel( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table_name(),
			[
				'status'     => self::STATUS_CANCELLED,
				'due_at'     => null,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id, 'status' => self::STATUS_ACTIVE ],
			[ '%s', '%s', '%s' ],
			[ '%d', '%s' ]
		);

		return (bool) $updated;
	}

	/**
	 * Cancel all active enrollments for a recipient + trigger pair.
	 *
	 * @return int Number of rows cancelled.
	 */
	public static function cancel_active( int $trigger_post_id, string $recipient_email ): int {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$recipient_email = strtolower( trim( $recipient_email ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			self::table_name(),
			[
				'status'     => self::STATUS_CANCELLED,
				'due_at'     => null,
				'updated_at' => current_time( 'mysql', true ),
			],
			[
				'trigger_post_id' => $trigger_post_id,
				'recipient_email' => $recipient_email,
				'status'          => self::STATUS_ACTIVE,
			],
			[ '%s', '%s', '%s' ],
			[ '%d', '%s', '%s' ]
		);

		return (int) $updated;
	}

	/**
	 * Fetch a single enrollment row by ID.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 || ! self::table_exists() ) {
			return null;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * List enrollments, most recent first, optionally filtered by status.
	 *
	 * @param string $status One of active/completed/cancelled, or '' for all.
	 * @param int    $limit  Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( string $status = '', int $limit = 100 ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return [];
		}

		$limit = max( 1, $limit );
		$table = self::table_name();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d",
					$status,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Decode a JSON array column, tolerating empty / malformed values.
	 *
	 * @param mixed $value Column value.
	 * @return array<int|string, mixed>
	 */
	public static function decode_json_array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
