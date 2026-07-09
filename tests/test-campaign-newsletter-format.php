<?php
declare(strict_types=1);
/**
 * Regression coverage for campaign newsletter format enforcement.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-campaign-newsletter-format.php
 */

namespace {
	class WP_Term {
		public function __construct(
			public readonly int $term_id,
			public readonly string $slug
		) {}
	}

	$object_terms = array();
	$set_terms    = array();

	function wp_get_object_terms( int $post_id, string $taxonomy ): array {
		global $object_terms;
		return $object_terms[ $post_id ] ?? array();
	}

	function wp_set_object_terms( int $post_id, string $term_slug, string $taxonomy, bool $append ): array {
		global $set_terms;
		$set_terms[] = array(
			'post_id'   => $post_id,
			'term_slug' => $term_slug,
			'taxonomy'  => $taxonomy,
			'append'    => $append,
		);
		return array();
	}

	function is_wp_error( $value ): bool {
		return false;
	}
}

namespace PRC\Platform\Email_Builder {
	class Loader {
		public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {}
		public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			return true;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function assert_contains( array $haystack, string $needle, string $message ): void {
		assert_true( in_array( $needle, $haystack, true ), $message );
	}

	function assert_not_contains( array $haystack, string $needle, string $message ): void {
		assert_true( ! in_array( $needle, $haystack, true ), $message );
	}

	$post_type = new Post_Type( new Loader() );

	$formats_post_types = array( 'post', 'short-read' );
	$formats_post_types   = $post_type->opt_campaign_into_formats_taxonomy( $formats_post_types );

	assert_contains(
		$formats_post_types,
		Post_Type::CAMPAIGN_POST_TYPE,
		'Campaign CPT should be opted into the formats taxonomy.'
	);
	assert_not_contains(
		$formats_post_types,
		Post_Type::TRANSACTIONAL_POST_TYPE,
		'Transactional CPT should not be opted into the formats taxonomy.'
	);

	$campaign_post = (object) array(
		'ID'        => 101,
		'post_type' => Post_Type::CAMPAIGN_POST_TYPE,
	);
	$txn_post      = (object) array(
		'ID'        => 202,
		'post_type' => Post_Type::TRANSACTIONAL_POST_TYPE,
	);

	global $object_terms, $set_terms;

	$post_type->enforce_campaign_newsletter_format( $campaign_post );

	assert_true(
		1 === count( $set_terms ),
		'Campaign post without newsletter format should trigger wp_set_object_terms once.'
	);
	assert_true(
		101 === $set_terms[0]['post_id'] && 'newsletter' === $set_terms[0]['term_slug'] && true === $set_terms[0]['append'],
		'Campaign post should append the newsletter format term.'
	);

	$set_terms = array();
	$object_terms[ 101 ] = array( new \WP_Term( 1, 'newsletter' ) );

	$post_type->enforce_campaign_newsletter_format( $campaign_post );

	assert_true(
		0 === count( $set_terms ),
		'Campaign post that already has newsletter format should not set terms again.'
	);

	$post_type->enforce_campaign_newsletter_format( $txn_post );

	assert_true(
		0 === count( $set_terms ),
		'Transactional post should not receive the newsletter format.'
	);

	fwrite( STDOUT, "OK: campaign newsletter format tests passed.\n" );
}
