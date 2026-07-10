<?php
declare(strict_types=1);
/**
 * Regression coverage for campaign vs transactional post-type supports.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-post-type-supports.php
 */

namespace {
	$registered_post_types = array();

	function register_post_type( string $post_type, array $args ): void {
		global $registered_post_types;
		$registered_post_types[ $post_type ] = $args;
	}
}

namespace PRC\Platform\Email_Builder {
	class Loader {
		public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {}
		public function add_filter( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			return true;
		}
	}

	require_once dirname( __DIR__ ) . '/includes/class-rewrites.php';
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

	function assert_editor_notes_enabled( array $supports, string $message ): void {
		assert_true(
			isset( $supports['editor'] ) && is_array( $supports['editor'] ) && ! empty( $supports['editor']['notes'] ),
			$message
		);
	}

	$post_type = new Post_Type( new Loader() );
	$post_type->register_post_types();

	global $registered_post_types;

	assert_true(
		isset( $registered_post_types[ Post_Type::CAMPAIGN_POST_TYPE ]['supports'] ),
		'campaign post type should be registered with supports'
	);
	assert_true(
		isset( $registered_post_types[ Post_Type::TRANSACTIONAL_POST_TYPE ]['supports'] ),
		'transactional post type should be registered with supports'
	);

	$campaign_supports      = $registered_post_types[ Post_Type::CAMPAIGN_POST_TYPE ]['supports'];
	$transactional_supports = $registered_post_types[ Post_Type::TRANSACTIONAL_POST_TYPE ]['supports'];

	assert_contains(
		$campaign_supports,
		'prc-publication-listing',
		'campaign post type should support prc-publication-listing'
	);
	assert_contains(
		$campaign_supports,
		'prc-schema-seo',
		'campaign post type should support prc-schema-seo'
	);

	$campaign_taxonomies = $registered_post_types[ Post_Type::CAMPAIGN_POST_TYPE ]['taxonomies'] ?? [];
	assert_contains(
		$campaign_taxonomies,
		'_post_visibility',
		'campaign post type should register _post_visibility so Hide on Publications Archive persists'
	);

	assert_not_contains(
		$transactional_supports,
		'prc-publication-listing',
		'transactional post type should not support prc-publication-listing'
	);
	assert_not_contains(
		$transactional_supports,
		'prc-schema-seo',
		'transactional post type should not support prc-schema-seo'
	);
	assert_contains(
		$transactional_supports,
		'prc-post-publish-pipeline',
		'transactional post type should still support prc-post-publish-pipeline'
	);
	assert_editor_notes_enabled(
		$campaign_supports,
		'campaign post type should enable editor notes'
	);
	assert_editor_notes_enabled(
		$transactional_supports,
		'transactional post type should enable editor notes'
	);

	fwrite( STDOUT, "OK: post type supports tests passed.\n" );
}
