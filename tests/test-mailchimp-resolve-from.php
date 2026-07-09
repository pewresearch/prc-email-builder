<?php
declare(strict_types=1);
/**
 * Mailchimp per-list From resolution coverage.
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-mailchimp-resolve-from.php
 */

namespace {

	if ( ! defined( 'PRC_PLATFORM_MAILCHIMP_KEY' ) ) {
		define( 'PRC_PLATFORM_MAILCHIMP_KEY', 'test-key-us21' );
	}

	$GLOBALS['__options']   = [];
	$GLOBALS['__term_meta'] = [];
	$GLOBALS['__object_terms'] = [];

	function get_option( string $key, $default = false ) {
		return $GLOBALS['__options'][ $key ] ?? $default;
	}

	function wp_get_object_terms( int $object_id, string $taxonomy, array $args = [] ) {
		unset( $object_id, $taxonomy, $args );
		return $GLOBALS['__object_terms'];
	}

	function get_term_meta( int $term_id, string $key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['__term_meta'][ $term_id ][ $key ] ?? '';
	}

	function is_email( string $email ): bool {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	function wp_parse_args( $args, $defaults = [] ): array {
		if ( ! is_array( $args ) ) {
			return (array) $defaults;
		}
		return array_merge( $defaults, $args );
	}

	function is_wp_error( $thing ): bool {
		return false;
	}

	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
	require_once dirname( __DIR__ ) . '/includes/mailchimp/class-mailchimp.php';

	$failures = 0;

	function assert_same( string $expected, string $actual, string $label ): void {
		global $failures;
		if ( $expected !== $actual ) {
			++$failures;
			echo "FAIL: {$label}\n  expected: {$expected}\n  actual:   {$actual}\n";
		}
	}

	$GLOBALS['__options']['prc_email_builder_settings'] = [
		'from_name'  => 'Global Sender',
		'from_email' => 'global@example.test',
	];

	// No list term assigned → global settings.
	$GLOBALS['__object_terms'] = [];
	$resolved = \PRC\Platform\Email_Builder\Mailchimp::resolve_from_for_post( 100 );
	assert_same( 'Global Sender', $resolved['from_name'], 'fallback from_name without list term' );
	assert_same( 'global@example.test', $resolved['from_email'], 'fallback from_email without list term' );

	// List term with partial override → name only from list, email from global.
	$GLOBALS['__object_terms'] = [ 42 ];
	$GLOBALS['__term_meta'][42] = [
		'prc_newsletter_list_from_name'  => 'List Sender',
		'prc_newsletter_list_from_email' => '',
	];
	$resolved = \PRC\Platform\Email_Builder\Mailchimp::resolve_from_for_post( 101 );
	assert_same( 'List Sender', $resolved['from_name'], 'list from_name override' );
	assert_same( 'global@example.test', $resolved['from_email'], 'global from_email when list email empty' );

	// List term with both overrides.
	$GLOBALS['__term_meta'][42]['prc_newsletter_list_from_email'] = 'list@example.test';
	$resolved = \PRC\Platform\Email_Builder\Mailchimp::resolve_from_for_post( 102 );
	assert_same( 'List Sender', $resolved['from_name'], 'list from_name with full override' );
	assert_same( 'list@example.test', $resolved['from_email'], 'list from_email override' );

	// Invalid list email is ignored → global email kept.
	$GLOBALS['__term_meta'][42]['prc_newsletter_list_from_email'] = 'not-an-email';
	$resolved = \PRC\Platform\Email_Builder\Mailchimp::resolve_from_for_post( 103 );
	assert_same( 'List Sender', $resolved['from_name'], 'list from_name with invalid email' );
	assert_same( 'global@example.test', $resolved['from_email'], 'global from_email when list email invalid' );

	if ( $failures > 0 ) {
		echo "\n{$failures} assertion(s) failed.\n";
		exit( 1 );
	}

	echo "OK: all Mailchimp From resolution assertions passed.\n";
}
