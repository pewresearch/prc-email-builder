<?php
declare(strict_types=1);
/**
 * Rewrite and permalink coverage for newsletter lists and campaigns.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-rewrites.php
 */

namespace {
	$registered_post_types = [];
	$registered_taxonomies = [];

	function register_post_type( string $post_type, array $args ): void {
		global $registered_post_types;
		$registered_post_types[ $post_type ] = $args;
	}

	function register_taxonomy( string $taxonomy, array|string $object_type, array $args = [] ): void {
		global $registered_taxonomies;
		$registered_taxonomies[ $taxonomy ] = $args;
	}

	function is_wp_error( mixed $thing ): bool {
		return false;
	}

	class WP_Term {
		public function __construct(
			public readonly int $term_id,
			public readonly string $slug
		) {}
	}

	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type
		) {}
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

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite(
				STDERR,
				sprintf(
					"FAIL: %s (expected %s, got %s)\n",
					$message,
					var_export( $expected, true ),
					var_export( $actual, true )
				)
			);
			exit( 1 );
		}
	}

	$post_type = new Post_Type( new Loader() );
	$post_type->register_taxonomy();
	$post_type->register_post_types();

	global $registered_post_types, $registered_taxonomies;

	$campaign_args = $registered_post_types[ Post_Type::CAMPAIGN_POST_TYPE ] ?? [];
	$taxonomy_args = $registered_taxonomies[ Post_Type::TAXONOMY ] ?? [];

	assert_true(
		false === ( $campaign_args['has_archive'] ?? true ),
		'campaign post type should not expose a global archive'
	);
	assert_same(
		'newsletter/%prc_newsletter_list%',
		$campaign_args['rewrite']['slug'] ?? '',
		'campaign rewrite slug should include the newsletter list placeholder'
	);
	assert_true(
		false === ( $taxonomy_args['public'] ?? true ),
		'newsletter list taxonomy should remain non-public'
	);
	assert_true(
		true === ( $taxonomy_args['publicly_queryable'] ?? false ),
		'newsletter list taxonomy should be publicly queryable'
	);
	assert_same(
		'newsletter',
		$taxonomy_args['rewrite']['slug'] ?? '',
		'newsletter list taxonomy rewrite slug should be newsletter'
	);

	$rewrites = new Rewrites( new Loader() );
	$campaign = new \WP_Post( 42, Post_Type::CAMPAIGN_POST_TYPE );

	function wp_get_object_terms( int $post_id, string $taxonomy, array $args = [] ) {
		if ( 42 === $post_id ) {
			return [ new \WP_Term( 7, 'the-briefing' ) ];
		}
		if ( 99 === $post_id ) {
			return [];
		}
		return [];
	}

	$with_list = $rewrites->filter_campaign_permalink(
		'https://example.org/newsletter/%prc_newsletter_list%/my-campaign/',
		$campaign
	);
	assert_same(
		'https://example.org/newsletter/the-briefing/my-campaign/',
		$with_list,
		'post_type_link filter should replace the list placeholder with the assigned term slug'
	);

	$listless = new \WP_Post( 99, Post_Type::CAMPAIGN_POST_TYPE );
	$fallback = $rewrites->filter_campaign_permalink(
		'https://example.org/newsletter/%prc_newsletter_list%/orphan-campaign/',
		$listless
	);
	assert_same(
		'https://example.org/newsletter/campaign/orphan-campaign/',
		$fallback,
		'post_type_link filter should fall back to the static list segment when no term is assigned'
	);

	assert_same(
		'the-briefing',
		Rewrites::get_list_slug_for_post( 42 ),
		'get_list_slug_for_post should return the assigned list slug'
	);
	assert_same(
		Rewrites::LISTLESS_FALLBACK_SLUG,
		Rewrites::get_list_slug_for_post( 99 ),
		'get_list_slug_for_post should return the fallback slug when no list is assigned'
	);

	$excluded = $rewrites->exclude_newsletter_slug( [ 'post' ] );
	assert_true(
		in_array( 'newsletter', $excluded, true ),
		'newsletter should be excluded from research-team URL matching'
	);

	fwrite( STDOUT, "OK: rewrite tests passed.\n" );
}
