<?php
/**
 * Custom table for durable system-email recipient logging.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Manages the prc_system_email_recipients table and recipient queries.
 */
class System_Email_Recipients_Table {

	const TABLE_NAME     = 'prc_system_email_recipients';
	const DB_VERSION_KEY = 'prc_system_email_recipients_db_version';
	const DB_VERSION     = '1.0.0';

	/**
	 * Full table name including the WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Whether the recipients table exists.
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
	 * Create or upgrade the recipients table.
	 */
	public static function create_table(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			system_email_key varchar(191) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			first_sent_at datetime NOT NULL,
			last_sent_at datetime NOT NULL,
			send_count int(10) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY recipient (system_email_key,email),
			KEY post_id (post_id)
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
	 * Record or bump a recipient for a keyed system email template.
	 *
	 * @param string $system_email_key Template lookup key.
	 * @param int    $post_id          Newsletter post ID at send time.
	 * @param string $email            Validated recipient address (lowercase).
	 */
	public static function upsert_recipient( string $system_email_key, int $post_id, string $email ): void {
		global $wpdb;

		self::maybe_create_table();

		$system_email_key = sanitize_text_field( $system_email_key );
		$email            = strtolower( trim( $email ) );

		if ( '' === $system_email_key || ! is_email( $email ) || $post_id <= 0 ) {
			return;
		}

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(system_email_key, post_id, email, first_sent_at, last_sent_at, send_count)
				VALUES (%s, %d, %s, %s, %s, 1)
				ON DUPLICATE KEY UPDATE
					send_count = send_count + 1,
					last_sent_at = %s,
					post_id = VALUES(post_id)",
				$system_email_key,
				$post_id,
				$email,
				$now,
				$now,
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Parse a comma-separated system-email-key filter string into SQL fragments.
	 *
	 * Supports exact keys and trailing-wildcard prefixes (e.g. typology-2026-*).
	 *
	 * @param string $raw Comma-separated filter values.
	 * @return array<int, array{type: 'exact'|'prefix', value: string}>
	 */
	public static function parse_key_filters( string $raw ): array {
		$filters = array();
		$parts   = preg_split( '/\s*,\s*/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return $filters;
		}

		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' === $part ) {
				continue;
			}

			if ( str_ends_with( $part, '*' ) ) {
				$prefix = rtrim( $part, '*' );
				if ( '' === $prefix ) {
					continue;
				}
				$filters[] = array(
					'type'  => 'prefix',
					'value' => sanitize_text_field( $prefix ),
				);
				continue;
			}

			$filters[] = array(
				'type'  => 'exact',
				'value' => sanitize_text_field( $part ),
			);
		}

		return $filters;
	}

	/**
	 * Fetch distinct recipient emails matching one or more system-email-key filters.
	 *
	 * @param array<int, array{type: 'exact'|'prefix', value: string}> $filters Parsed filters.
	 * @return string[] Lowercase, sorted, unique email addresses.
	 */
	public static function get_distinct_emails( array $filters ): array {
		global $wpdb;

		if ( empty( $filters ) ) {
			return array();
		}

		self::maybe_create_table();

		if ( ! self::table_exists() ) {
			return array();
		}

		$table   = self::table_name();
		$clauses = array();
		$prepare = array();

		foreach ( $filters as $filter ) {
			if ( 'prefix' === ( $filter['type'] ?? '' ) ) {
				$clauses[] = 'system_email_key LIKE %s';
				$prepare[] = $wpdb->esc_like( (string) $filter['value'] ) . '%';
				continue;
			}

			$clauses[] = 'system_email_key = %s';
			$prepare[] = (string) ( $filter['value'] ?? '' );
		}

		if ( empty( $clauses ) ) {
			return array();
		}

		$sql = 'SELECT DISTINCT email FROM ' . $table . ' WHERE (' . implode( ' OR ', $clauses ) . ') ORDER BY email ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$prepare ) );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$emails = array();
		foreach ( $rows as $email ) {
			$normalized = strtolower( trim( (string) $email ) );
			if ( '' !== $normalized && is_email( $normalized ) ) {
				$emails[ $normalized ] = $normalized;
			}
		}

		return array_values( $emails );
	}

	/**
	 * Distinct recipient count for one system-email post.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	public static function count_for_post( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		self::maybe_create_table();
		if ( ! self::table_exists() ) {
			return 0;
		}

		global $wpdb;
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT email) FROM {$table} WHERE post_id = %d",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}
}
