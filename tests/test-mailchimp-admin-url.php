<?php
declare(strict_types=1);
/**
 * Mailchimp campaign admin URL builder coverage.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-mailchimp-admin-url.php
 */

namespace {

	if ( ! defined( 'PRC_PLATFORM_MAILCHIMP_KEY' ) ) {
		define( 'PRC_PLATFORM_MAILCHIMP_KEY', 'test-key-us21' );
	}

	function get_option( string $key, $default = false ) {
		return $default;
	}

	require_once dirname( __DIR__ ) . '/includes/mailchimp/class-mailchimp.php';

	$failures = 0;

	function assert_same( string $expected, string $actual, string $label ): void {
		global $failures;
		if ( $expected !== $actual ) {
			++$failures;
			echo "FAIL: {$label}\n  expected: {$expected}\n  actual:   {$actual}\n";
		}
	}

	assert_same(
		'us21',
		\PRC\Platform\Email_Builder\Mailchimp::get_mailchimp_data_center(),
		'data center from API key suffix'
	);

	assert_same(
		'https://us21.admin.mailchimp.com/campaigns/edit?id=12345',
		\PRC\Platform\Email_Builder\Mailchimp::build_campaign_admin_url( 12345 ),
		'edit URL with web_id'
	);

	assert_same(
		'https://admin.mailchimp.com/campaigns/',
		\PRC\Platform\Email_Builder\Mailchimp::build_campaign_admin_url( 0 ),
		'fallback when web_id missing'
	);

	if ( $failures > 0 ) {
		echo "\n{$failures} assertion(s) failed.\n";
		exit( 1 );
	}

	echo "OK: all Mailchimp admin URL assertions passed.\n";
}
