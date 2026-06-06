<?php
declare(strict_types=1);
/**
 * Regression coverage for current-only email post type classification.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-post-type-classification.php
 */

namespace {
	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type,
			public readonly string $post_status = 'draft'
		) {}
	}
}

namespace PRC\Platform\Email_Builder {
	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	assert_true(
		Post_Type::is_email_post_type( Post_Type::CAMPAIGN_POST_TYPE ),
		'Campaign CPT should be recognized as an email post type.'
	);
	assert_true(
		Post_Type::is_email_post_type( Post_Type::TRANSACTIONAL_POST_TYPE ),
		'Transactional CPT should be recognized as an email post type.'
	);
	assert_true(
		! Post_Type::is_email_post_type( 'prc_newsletter' ),
		'Legacy prc_newsletter should not be recognized.'
	);

	$campaign = new \WP_Post( 1, Post_Type::CAMPAIGN_POST_TYPE );
	$txn      = new \WP_Post( 2, Post_Type::TRANSACTIONAL_POST_TYPE );
	$legacy   = new \WP_Post( 3, 'prc_newsletter' );

	assert_true(
		Post_Type::is_campaign_post( $campaign ),
		'Campaign helper should accept prc_email_campaign.'
	);
	assert_true(
		! Post_Type::is_campaign_post( $txn ),
		'Campaign helper should reject transactional posts.'
	);
	assert_true(
		! Post_Type::is_campaign_post( $legacy ),
		'Campaign helper should reject legacy prc_newsletter.'
	);

	assert_true(
		Post_Type::is_transactional_post( $txn ),
		'Transactional helper should accept prc_email_txn.'
	);
	assert_true(
		! Post_Type::is_transactional_post( $campaign ),
		'Transactional helper should reject campaign posts.'
	);
	assert_true(
		! Post_Type::is_transactional_post( $legacy ),
		'Transactional helper should reject legacy prc_newsletter.'
	);

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		global $test_meta;
		return $test_meta[ $post_id ][ $key ] ?? '';
	}

	$GLOBALS['test_meta'] = [];

	assert_true(
		'mandrill' === Post_Type::transactional_delivery_mode( $txn ),
		'Empty delivery_mode meta should default to mandrill on transactional posts.'
	);

	$GLOBALS['test_meta'][ 2 ] = [ 'prc_email_delivery_mode' => 'dynamic' ];
	assert_true(
		'dynamic' === Post_Type::transactional_delivery_mode( $txn ),
		'Explicit dynamic delivery_mode should be preserved.'
	);

	$GLOBALS['test_meta'][ 2 ] = [ 'prc_email_delivery_mode' => 'mandrill' ];
	assert_true(
		'mandrill' === Post_Type::transactional_delivery_mode( $txn ),
		'Explicit mandrill delivery_mode should be preserved.'
	);

	fwrite( STDOUT, "OK: post type classification tests passed.\n" );
}
