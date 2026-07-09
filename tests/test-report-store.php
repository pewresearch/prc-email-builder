<?php
declare(strict_types=1);
/**
 * Report_Store persistence coverage.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-report-store.php
 */

namespace {

	$GLOBALS['__meta'] = [];

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return $GLOBALS['__meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, $value ): void {
		$GLOBALS['__meta'][ $post_id ][ $key ] = $value;
	}

	function wp_json_encode( $data ): string {
		return json_encode( $data ) ?: '';
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	$failures = 0;

	function check( bool $condition, string $message ): void {
		global $failures;
		if ( $condition ) {
			echo "  PASS: {$message}\n";
		} else {
			echo "  FAIL: {$message}\n";
			$failures++;
		}
	}
}

namespace PRC\Platform\Email_Builder\Reports {

	require_once dirname( __DIR__ ) . '/includes/reports/class-report-store.php';

	echo "Report store tests\n";

	$post_id = 101;
	$normalized = [
		'channel'   => 'mailchimp',
		'summary'   => [
			'open_rate'  => 0.25,
			'click_rate' => 0.05,
		],
		'clicks_by_url' => [],
		'send_time' => '2026-06-01T10:00:00+00:00',
	];

	Report_Store::save_report( $post_id, $normalized );

	\check( Report_Store::get_report( $post_id ) !== null, 'report JSON round-trips' );
	\check(
		(float) \get_post_meta( $post_id, Report_Store::META_OPEN_RATE, true ) === 0.25,
		'open rate meta written'
	);
	\check(
		(string) \get_post_meta( $post_id, Report_Store::META_SEND_TIME, true ) === '2026-06-01T10:00:00+00:00',
		'send_time persisted on first save'
	);

	$normalized['send_time'] = '2026-06-02T10:00:00+00:00';
	Report_Store::save_report( $post_id, $normalized );
	\check(
		(string) \get_post_meta( $post_id, Report_Store::META_SEND_TIME, true ) === '2026-06-01T10:00:00+00:00',
		'send_time not overwritten on later save'
	);

	Report_Store::mark_unavailable( $post_id );
	\check(
		Report_Store::get_sync_state( $post_id ) === Report_Store::STATE_UNAVAILABLE,
		'unavailable state set'
	);
	\check( Report_Store::get_report( $post_id ) !== null, 'prior report retained on unavailable' );

	echo "\n";
	if ( $failures > 0 ) {
		echo "RESULT: {$failures} assertion(s) failed.\n";
		exit( 1 );
	}
	echo "RESULT: all report store tests passed.\n";
}
