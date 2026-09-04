<?php
/**
 * Full-audience Mandrill event storage and aggregation.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Report_Schema;

/**
 * Per-post Mandrill events keyed by salted recipient hash, not raw email.
 */
class Mandrill_Event_Ledger {

	public const TABLE              = 'prc_email_mandrill_events';
	public const DB_VERSION_KEY     = 'prc_email_mandrill_events_db_version';
	public const DB_VERSION         = '1.0.0';
	public const WEBHOOK_KEY_OPTION = 'prc_email_mandrill_webhook_key';

	/**
	 * Register ingest hook and ensure the table exists in admin/CLI.
	 */
	public static function init(): void {
		add_action( 'prc_email_mandrill_webhook_events', array( self::class, 'ingest_events' ) );
		add_action( 'admin_init', array( self::class, 'maybe_create_table' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( self::class, 'maybe_create_table' ) );
		}
	}

	/**
	 * Full table name including the WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether the events table exists.
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
	 * Create or upgrade the events table.
	 */
	public static function create_table(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			recipient_hash char(64) NOT NULL,
			event_type varchar(20) NOT NULL,
			url_hash char(64) NOT NULL DEFAULT '',
			url varchar(500) NOT NULL DEFAULT '',
			occurred_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event (post_id, recipient_hash, event_type, url_hash, occurred_at),
			KEY post_event (post_id, event_type),
			KEY occurred (occurred_at)
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
	 * Decode Mandrill's mandrill_events payload.
	 *
	 * @param mixed $raw JSON string or array.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse_mandrill_events( $raw ): array {
		if ( is_array( $raw ) ) {
			$events = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$events  = is_array( $decoded ) ? $decoded : array();
		} else {
			$events = array();
		}

		$out = array();
		foreach ( $events as $event ) {
			if ( is_array( $event ) ) {
				$out[] = $event;
			}
		}
		return $out;
	}

	/**
	 * Normalize a Mandrill event name into the stored enum.
	 *
	 * @param string $event Raw event name.
	 * @return string Empty when the event is not stored.
	 */
	public static function normalize_event_type( string $event ): string {
		$value = strtolower( trim( $event ) );
		$map   = array(
			'send'         => 'sent',
			'sent'         => 'sent',
			'open'         => 'open',
			'click'        => 'click',
			'bounce'       => 'bounce',
			'hard_bounce'  => 'bounce',
			'soft_bounce'  => 'bounce',
			'reject'       => 'reject',
			'spam'         => 'spam',
			'unsub'        => 'unsub',
			'unsubscribed' => 'unsub',
		);
		if ( ! isset( $map[ $value ] ) ) {
			return '';
		}
		return $map[ $value ];
	}

	/**
	 * Salted recipient identity. Unique counts need identity, not PII.
	 *
	 * @param string $email Recipient address.
	 */
	public static function hash_recipient( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}

	/**
	 * Store Mandrill webhook event objects. Idempotent (INSERT IGNORE).
	 *
	 * @param array<int, mixed> $events Mandrill event objects.
	 * @return int Rows stored (inserts that affected a row).
	 */
	public static function ingest_events( array $events ): int {
		self::maybe_create_table();
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;
		$table  = self::table_name();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$stored = 0;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$type = self::normalize_event_type( (string) ( $event['event'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}

			$msg   = isset( $event['msg'] ) && is_array( $event['msg'] ) ? $event['msg'] : array();
			$email = strtolower( trim( (string) ( $msg['email'] ?? '' ) ) );
			if ( '' === $email ) {
				continue;
			}

			$metadata = isset( $msg['metadata'] ) && is_array( $msg['metadata'] ) ? $msg['metadata'] : array();
			$post_id  = isset( $metadata['email_post_id'] ) ? (int) $metadata['email_post_id'] : 0;
			if ( $post_id <= 0 ) {
				continue;
			}

			$url = '';
			if ( isset( $event['url'] ) ) {
				$url = substr( esc_url_raw( (string) $event['url'] ), 0, 500 );
			}
			$url_hash = '' === $url ? '' : hash( 'sha256', $url );

			$ts          = isset( $event['ts'] ) ? (int) $event['ts'] : time();
			$occurred_at = gmdate( 'Y-m-d H:i:s', $ts > 0 ? $ts : time() );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(post_id, recipient_hash, event_type, url_hash, url, occurred_at, created_at)
					VALUES (%d, %s, %s, %s, %s, %s, %s)",
					$post_id,
					self::hash_recipient( $email ),
					$type,
					$url_hash,
					$url,
					$occurred_at,
					$now
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( false !== $result && (int) $result > 0 ) {
				++$stored;
			}
		}

		return $stored;
	}

	/**
	 * Full-audience engagement for one post.
	 *
	 * Tracked recipients are distinct hashes with a sent event, not every event type.
	 *
	 * @param int         $post_id     Email post ID.
	 * @param string|null $since_mysql Inclusive lower bound (GMT datetime) or null.
	 * @return array{
	 *   opens_total: int,
	 *   opens_unique: int,
	 *   clicks_total: int,
	 *   clicks_unique: int,
	 *   bounces: int,
	 *   unsubs: int,
	 *   spam: int,
	 *   clicks_by_url: array<int, array{url: string, clicks: int}>,
	 *   tracked_recipients: int
	 * }
	 */
	public static function aggregate( int $post_id, ?string $since_mysql ): array {
		$empty = array(
			'opens_total'        => 0,
			'opens_unique'       => 0,
			'clicks_total'       => 0,
			'clicks_unique'      => 0,
			'bounces'            => 0,
			'unsubs'             => 0,
			'spam'               => 0,
			'clicks_by_url'      => array(),
			'tracked_recipients' => 0,
		);

		if ( $post_id <= 0 || ! self::table_exists() ) {
			return $empty;
		}

		global $wpdb;
		$table      = self::table_name();
		$since_sql  = '';
		$since_args = array();
		if ( is_string( $since_mysql ) && '' !== $since_mysql ) {
			$since_sql    = ' AND occurred_at >= %s';
			$since_args[] = $since_mysql;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and optional since clause cannot be placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS total, COUNT(DISTINCT recipient_hash) AS uniques
				FROM {$table}
				WHERE post_id = %d{$since_sql}
				GROUP BY event_type",
				$post_id,
				...$since_args
			),
			\ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$by_type = array();
		foreach ( $rows as $row ) {
			$type = (string) ( $row['event_type'] ?? '' );
			if ( '' === $type ) {
				continue;
			}
			$by_type[ $type ] = array(
				'total'   => (int) ( $row['total'] ?? 0 ),
				'uniques' => (int) ( $row['uniques'] ?? 0 ),
			);
		}

		$empty['opens_total']   = (int) ( $by_type['open']['total'] ?? 0 );
		$empty['opens_unique']  = (int) ( $by_type['open']['uniques'] ?? 0 );
		$empty['clicks_total']  = (int) ( $by_type['click']['total'] ?? 0 );
		$empty['clicks_unique'] = (int) ( $by_type['click']['uniques'] ?? 0 );
		$empty['bounces']       = (int) ( $by_type['bounce']['uniques'] ?? 0 ) + (int) ( $by_type['reject']['uniques'] ?? 0 );
		$empty['unsubs']        = (int) ( $by_type['unsub']['uniques'] ?? 0 );
		$empty['spam']          = (int) ( $by_type['spam']['uniques'] ?? 0 );

		$tracked                     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT recipient_hash) FROM {$table} WHERE post_id = %d AND event_type = %s{$since_sql}",
				$post_id,
				'sent',
				...$since_args
			)
		);
		$empty['tracked_recipients'] = (int) $tracked;

		$cap        = Report_Schema::click_url_cap();
		$click_args = array_merge( array( $post_id, 'click' ), $since_args, array( $cap ) );
		$click_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, COUNT(*) AS clicks
				FROM {$table}
				WHERE post_id = %d AND event_type = %s AND url <> ''{$since_sql}
				GROUP BY url
				ORDER BY clicks DESC, url ASC
				LIMIT %d",
				...$click_args
			),
			\ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( ! is_array( $click_rows ) ) {
			$click_rows = array();
		}

		$clicks_by_url = array();
		foreach ( $click_rows as $row ) {
			$url = (string) ( $row['url'] ?? '' );
			if ( '' === $url ) {
				continue;
			}
			$clicks_by_url[] = array(
				'url'    => $url,
				'clicks' => (int) ( $row['clicks'] ?? 0 ),
			);
		}
		$empty['clicks_by_url'] = $clicks_by_url;

		return $empty;
	}

	/**
	 * Site webhook key. Created once.
	 */
	public static function webhook_key(): string {
		$key = get_option( self::WEBHOOK_KEY_OPTION, '' );
		if ( ! is_string( $key ) || strlen( $key ) < 16 ) {
			$key = wp_generate_password( 32, false, false );
			update_option( self::WEBHOOK_KEY_OPTION, $key, false );
		}
		return $key;
	}

	/**
	 * Whether the request key matches the stored webhook key.
	 *
	 * @param string $provided Incoming key.
	 */
	public static function webhook_key_is_valid( string $provided ): bool {
		$key = self::webhook_key();
		return '' !== $provided && hash_equals( $key, $provided );
	}
}
