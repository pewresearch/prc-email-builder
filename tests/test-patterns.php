<?php
declare(strict_types=1);
/**
 * Regression coverage for email pattern category registration.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-patterns.php
 */

namespace {
	function register_block_pattern_category( string $slug, array $args ): void {
		global $registered_pattern_categories;
		$registered_pattern_categories[ $slug ] = $args;
	}

	function _x( string $text, string $context, string $domain ): string {
		return $text;
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

namespace PRC\Platform\Email_Builder {
	require_once dirname( __DIR__ ) . '/includes/class-loader.php';
	require_once dirname( __DIR__ ) . '/includes/class-patterns.php';

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	$GLOBALS['registered_pattern_categories'] = [];

	$loader = new Loader();
	$patterns = new Patterns( $loader );
	$patterns->register_category();

	assert_true(
		isset( $GLOBALS['registered_pattern_categories'][ Patterns::CAMPAIGN_CATEGORY_SLUG ] ),
		'Email campaign pattern category should be registered.'
	);
	assert_true(
		Patterns::CAMPAIGN_CATEGORY_SLUG === 'email-campaign',
		'Campaign pattern category slug should be email-campaign.'
	);
	assert_true(
		isset( $GLOBALS['registered_pattern_categories'][ Patterns::TRANSACTIONAL_CATEGORY_SLUG ] ),
		'Transactional email pattern category should be registered.'
	);
	assert_true(
		Patterns::TRANSACTIONAL_CATEGORY_SLUG === 'email-transactional',
		'Transactional pattern category slug should be email-transactional.'
	);

	$briefing = include dirname( __DIR__ ) . '/patterns/briefing.php';
	assert_true(
		is_array( $briefing ) && in_array( 'email-campaign', $briefing['categories'], true ),
		'Briefing pattern should be tagged with the email-campaign category.'
	);

	fwrite( STDOUT, "PASS: email pattern category registration\n" );
}
