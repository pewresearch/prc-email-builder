<?php
declare(strict_types=1);
/**
 * Coverage for System_Email_Send_Log and recipients table upserts.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-system-email-send-log.php
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/../../../../' );
	}

	$GLOBALS['__post_meta']      = [];
	$GLOBALS['__post_fields']    = [];
	$GLOBALS['__options']        = [ 'prc_system_email_recipients_db_version' => '1.0.0' ];
	$GLOBALS['__wpdb_queries']   = [];
	$GLOBALS['__table_exists']   = true;

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['__post_meta'][ $post_id ][ $key ] ?? '';
	}

	function get_post_field( string $field, int $post_id ) {
		if ( 'post_name' === $field ) {
			return $GLOBALS['__post_fields'][ $post_id ]['post_name'] ?? '';
		}

		return '';
	}

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}

	function update_option( string $key, $value ): bool {
		$GLOBALS['__options'][ $key ] = $value;
		return true;
	}

	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-07-02 12:00:00';
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	class wpdb {
		public string $prefix = 'wp_';

		public function prepare( string $query, ...$args ): string {
			$index = 0;
			return preg_replace_callback(
				'/%[dfs]/',
				static function () use ( $args, &$index ) {
					$value = $args[ $index++ ] ?? '';
					return is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
				},
				$query
			);
		}

		public function query( string $query ) {
			$GLOBALS['__wpdb_queries'][] = $query;
			return 1;
		}

		public function esc_like( string $value ): string {
			return addcslashes( $value, '_%\\' );
		}

		public function get_var( string $query ) {
			unset( $query );
			return $GLOBALS['__table_exists'] ? 'wp_prc_system_email_recipients' : null;
		}

		public function get_col( string $query ) {
			unset( $query );
			return [ 'one@example.com', 'two@example.com' ];
		}
	}

	$GLOBALS['wpdb'] = new wpdb();

	require_once dirname( __DIR__ ) . '/includes/system-email/class-system-email-recipients-table.php';
	require_once dirname( __DIR__ ) . '/includes/system-email/class-system-email-send-log.php';

	use PRC\Platform\Email_Builder\System_Email_Recipients_Table;
	use PRC\Platform\Email_Builder\System_Email_Send_Log;

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function assert_contains( string $needle, string $haystack, string $message ): void {
		assert_true( str_contains( $haystack, $needle ), $message );
	}

	// --- System_Email_Send_Log ---

	$GLOBALS['__post_meta']    = [];
	$GLOBALS['__wpdb_queries'] = [];

	$log = new System_Email_Send_Log( null );
	$log->record_send( 101, 'visitor@example.com', [] );
	assert_true( empty( $GLOBALS['__wpdb_queries'] ), 'Unkeyed templates must not write to the recipients table.' );

	$GLOBALS['__post_fields'][202] = [ 'post_name' => 'typology-2026-group3' ];
	$log->record_send( 202, 'Visitor@Example.com', [] );
	assert_true( 1 === count( $GLOBALS['__wpdb_queries'] ), 'Keyed templates must upsert one recipient row.' );
	assert_contains(
		"'typology-2026-group3'",
		$GLOBALS['__wpdb_queries'][0],
		'Upsert must include the system email key.'
	);
	assert_contains(
		"'visitor@example.com'",
		$GLOBALS['__wpdb_queries'][0],
		'Upsert must lowercase the recipient email.'
	);
	assert_contains(
		'ON DUPLICATE KEY UPDATE',
		$GLOBALS['__wpdb_queries'][0],
		'Repeat sends must bump send_count via upsert.'
	);

	$log->record_send( 202, 'not-an-email', [] );
	assert_true( 1 === count( $GLOBALS['__wpdb_queries'] ), 'Invalid emails must be ignored.' );

	// --- parse_key_filters ---

	$filters = System_Email_Recipients_Table::parse_key_filters( 'typology-2026-*,typology-2025-group1' );
	assert_true( 2 === count( $filters ), 'Comma-separated filters must parse to two entries.' );
	assert_true( 'prefix' === $filters[0]['type'], 'Trailing * must produce a prefix filter.' );
	assert_true( 'typology-2026-' === $filters[0]['value'], 'Prefix filter must strip the wildcard.' );
	assert_true( 'exact' === $filters[1]['type'], 'Non-wildcard values must be exact filters.' );

	fwrite( STDOUT, "OK: test-system-email-send-log.php\n" );
}
