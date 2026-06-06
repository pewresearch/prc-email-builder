<?php
declare(strict_types=1);
/**
 * WP-CLI commands for managing system-email (Mandrill) newsletter audiences.
 *
 * Audiences are stored as wp_options pairs:
 *   prc_email_audience_{key}      — array of email strings
 *   prc_email_audience_{key}_meta — label, count, built_at, etc.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Create, list, and delete system-email audience lists for Mandrill newsletters.
 */
class CLI_Audience {

	/**
	 * wp_options key prefix for audience email lists.
	 */
	const AUDIENCE_OPTION_PREFIX        = 'prc_email_audience_';
	const LEGACY_AUDIENCE_OPTION_PREFIX = 'prc_newsletter_audience_';

	/**
	 * Create or replace a test audience from comma-separated email addresses.
	 *
	 * ## OPTIONS
	 *
	 * --emails=<list>
	 * : Comma-separated email addresses.
	 *
	 * [--label=<text>]
	 * : Human-readable label for the sidebar picker. Default: "Test audience".
	 *
	 * [--key=<slug>]
	 * : Slug appended to the option prefix. Default: "test" (key: prc_email_audience_test).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience create --emails=you@example.com,qa@example.com
	 *
	 *     wp prc email audience create --emails=you@example.com --label="QA Mandrill test" --key=qa
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function create( $args, $assoc_args ): void {
		$raw_emails = Utils\get_flag_value( $assoc_args, 'emails', '' );
		$label      = Utils\get_flag_value( $assoc_args, 'label', 'Test audience' );
		$key_slug   = Utils\get_flag_value( $assoc_args, 'key', 'test' );

		if ( '' === trim( (string) $raw_emails ) ) {
			WP_CLI::error( '--emails is required (comma-separated list).' );
		}

		$parsed = $this->parse_and_validate_emails( (string) $raw_emails );
		if ( empty( $parsed['valid'] ) ) {
			WP_CLI::error( 'No valid email addresses found.' );
		}

		foreach ( $parsed['invalid'] as $invalid ) {
			WP_CLI::warning( sprintf( 'Skipping invalid email: %s', $invalid ) );
		}

		$audience_key = $this->normalize_audience_key( (string) $key_slug );
		$meta_key     = $audience_key . '_meta';
		$emails       = $parsed['valid'];
		$count        = count( $emails );

		$existing = get_option( $audience_key, false );
		if ( false !== $existing && is_array( $existing ) && ! empty( $existing ) ) {
			WP_CLI::warning( sprintf(
				'Overwriting existing audience "%s" (%d email(s)).',
				$audience_key,
				count( $existing )
			) );
		}

		$built_at = current_time( 'mysql', true );

		update_option( $audience_key, $emails, false );
		update_option(
			$meta_key,
			array(
				'label'    => $label,
				'count'    => $count,
				'built_at' => $built_at,
				'source'   => 'cli_test',
			),
			false
		);

		WP_CLI::success( sprintf(
			'Audience saved → %s (%d email(s), label: "%s")',
			$audience_key,
			$count,
			$label
		) );
	}

	/**
	 * Delete a system-email audience and its meta companion.
	 *
	 * ## OPTIONS
	 *
	 * --key=<key>
	 * : Full option key (e.g. prc_email_audience_test) or slug only (e.g. test).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience delete --key=test
	 *
	 *     wp prc email audience delete --key=prc_email_audience_test --yes
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ): void {
		$key_raw = Utils\get_flag_value( $assoc_args, 'key', '' );
		$yes     = Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( '' === trim( (string) $key_raw ) ) {
			WP_CLI::error( '--key is required.' );
		}

		$audience_key = $this->normalize_audience_key( (string) $key_raw );
		$meta_key     = $audience_key . '_meta';

		$existing = get_option( $audience_key, false );
		if ( false === $existing ) {
			WP_CLI::error( sprintf( 'Audience option "%s" does not exist.', $audience_key ) );
		}

		$referencing = $this->find_newsletters_using_audience( $audience_key );
		if ( ! empty( $referencing ) ) {
			WP_CLI::warning( sprintf(
				'%d newsletter post(s) reference this audience: %s',
				count( $referencing ),
				implode( ', ', $referencing )
			) );
		}

		if ( ! $yes ) {
			WP_CLI::confirm( sprintf( 'Delete audience "%s" and its meta?', $audience_key ) );
		}

		delete_option( $audience_key );
		delete_option( $meta_key );

		WP_CLI::success( sprintf( 'Deleted audience "%s".', $audience_key ) );
	}

	/**
	 * List all system-email audiences stored in wp_options.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email audience list
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function list( $args, $assoc_args ): void {
		$audiences = $this->fetch_audience_meta_rows();

		if ( empty( $audiences ) ) {
			WP_CLI::warning( 'No system-email audiences found.' );
			return;
		}

		$rows = array_map(
			static function ( array $audience ): array {
				return array(
					'key'      => $audience['key'],
					'label'    => $audience['label'],
					'count'    => $audience['count'],
					'built_at' => $audience['built_at'] ?? '—',
				);
			},
			$audiences
		);

		Utils\format_items( 'table', $rows, array( 'key', 'label', 'count', 'built_at' ) );
		WP_CLI::success( sprintf( '%d audience(s) found.', count( $rows ) ) );
	}

	/**
	 * Parse comma-separated emails; validate and dedupe (lowercase).
	 *
	 * @param string $raw Comma-separated addresses.
	 * @return array{valid: string[], invalid: string[]}
	 */
	private function parse_and_validate_emails( string $raw ): array {
		$parts   = preg_split( '/\s*,\s*/', trim( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$valid   = array();
		$invalid = array();

		foreach ( $parts as $part ) {
			$email = strtolower( trim( $part ) );
			if ( '' === $email ) {
				continue;
			}
			if ( is_email( $email ) ) {
				$valid[ $email ] = $email;
			} else {
				$invalid[] = $part;
			}
		}

		return array(
			'valid'   => array_values( $valid ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Normalize a slug or full option key to prc_email_audience_{slug}.
	 *
	 * @param string $key_or_slug Full key or slug.
	 * @return string
	 */
	private function normalize_audience_key( string $key_or_slug ): string {
		$key_or_slug = trim( $key_or_slug );

		if ( str_starts_with( $key_or_slug, self::LEGACY_AUDIENCE_OPTION_PREFIX ) ) {
			return $key_or_slug;
		}

		if ( str_starts_with( $key_or_slug, self::AUDIENCE_OPTION_PREFIX ) ) {
			return $key_or_slug;
		}

		$slug = sanitize_key( $key_or_slug );
		if ( '' === $slug ) {
			WP_CLI::error( 'Invalid --key: must contain alphanumeric characters.' );
		}

		return self::AUDIENCE_OPTION_PREFIX . $slug;
	}

	/**
	 * Fetch audience metadata rows (same discovery as REST list_system_audiences).
	 *
	 * @return array<int, array{key: string, label: string, count: int, built_at: string|null}>
	 */
	private function fetch_audience_meta_rows(): array {
		global $wpdb;

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$wpdb->esc_like( self::AUDIENCE_OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' ),
				$wpdb->esc_like( self::LEGACY_AUDIENCE_OPTION_PREFIX ) . '%' . $wpdb->esc_like( '_meta' )
			)
		);

		$audiences = array();
		foreach ( $results as $meta_option_name ) {
			$meta = get_option( $meta_option_name, array() );
			if ( empty( $meta ) || ! is_array( $meta ) || ! $this->is_audience_meta_array( $meta ) ) {
				continue;
			}

			$audience_key = substr( $meta_option_name, 0, -5 );
			$audiences[]  = array(
				'key'      => $audience_key,
				'label'    => (string) ( $meta['label'] ?? $audience_key ),
				'count'    => (int) ( $meta['count'] ?? 0 ),
				'built_at' => isset( $meta['built_at'] ) ? (string) $meta['built_at'] : null,
			);
		}

		return $audiences;
	}

	/**
	 * Distinguish meta companion options from audience email lists (slug may end in "_meta").
	 *
	 * @param array $meta Option value.
	 * @return bool
	 */
	private function is_audience_meta_array( array $meta ): bool {
		if ( array_is_list( $meta ) ) {
			return false;
		}

		return isset( $meta['label'] ) || isset( $meta['built_at'] ) || isset( $meta['source'] );
	}

	/**
	 * Find email post IDs that reference an audience option key.
	 *
	 * @param string $audience_key Option key.
	 * @return int[] Post IDs.
	 */
	private function find_newsletters_using_audience( string $audience_key ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'prc_email_audience_option_key',
				$audience_key
			)
		);

		return array_map( 'intval', $ids ?: array() );
	}
}
