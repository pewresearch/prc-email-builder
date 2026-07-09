<?php
declare(strict_types=1);
/**
 * Normalizer coverage for Mailchimp engagement reports.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-report-normalizer.php
 */

namespace {

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

	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

namespace PRC\Platform\Email_Builder\Reports {

	require_once dirname( __DIR__ ) . '/includes/reports/class-report-schema.php';

	$report_fixture = [
		'emails_sent'  => 1000,
		'send_time'    => '2026-06-01T12:00:00+00:00',
		'opens'        => [
			'opens_total'  => 500,
			'unique_opens' => 400,
			'open_rate'    => 0.4,
		],
		'clicks'       => [
			'clicks_total'   => 120,
			'unique_clicks'  => 80,
			'click_rate'     => 0.08,
		],
		'bounces'      => [
			'hard_bounces' => 2,
			'soft_bounces' => 3,
		],
		'unsubscribed' => 5,
		'abuse_reports' => 1,
		'members'      => [
			[ 'email_address' => 'should-not-persist@example.test' ],
		],
	];

	$clicks_fixture = [
		'urls_clicked' => [
			[
				'url'          => 'https://example.test/story-a/',
				'total_clicks' => 42,
			],
			[
				'url'          => 'https://example.test/story-b/',
				'total_clicks' => 7,
			],
		],
	];

	echo "Report normalizer tests\n";

	$normalized = Report_Schema::normalize_mailchimp( $report_fixture, $clicks_fixture );

	check( $normalized['channel'] === Report_Schema::CHANNEL_MAILCHIMP, 'channel is mailchimp' );
	check( (float) $normalized['summary']['open_rate'] === 0.4, 'open rate in [0,1]' );
	check( (float) $normalized['summary']['click_rate'] === 0.08, 'click rate in [0,1]' );
	check( (int) $normalized['summary']['abuse_reports'] === 1, 'abuse reports mapped' );
	check( count( $normalized['clicks_by_url'] ) === 2, 'click URLs preserved' );
	check( $normalized['clicks_by_url'][0]['clicks'] === 42, 'clicks ordered desc' );
	check( ! isset( $normalized['members'] ), 'member keys stripped from normalized output' );

	$partial = Report_Schema::normalize_mailchimp( $report_fixture, [] );
	check( count( $partial['clicks_by_url'] ) === 0, 'empty click details yields empty list' );

	echo "\n";
	if ( $failures > 0 ) {
		echo "RESULT: {$failures} assertion(s) failed.\n";
		exit( 1 );
	}
	echo "RESULT: all report normalizer tests passed.\n";
}
