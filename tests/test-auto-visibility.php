<?php
declare(strict_types=1);
/**
 * Regression coverage for transactional email auto-hide flags.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-auto-visibility.php
 */

namespace {
	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type,
			public readonly string $post_status = 'draft'
		) {}
	}

	$test_meta           = array();
	$updated_meta        = array();
	$visibility_terms    = array();
	$taxonomy_exists     = true;
	$cache_deletes       = array();

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		global $test_meta;
		return $test_meta[ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, $value ): void {
		global $updated_meta;
		$updated_meta[ $post_id ][ $key ] = $value;
	}

	function taxonomy_exists( string $taxonomy ): bool {
		global $taxonomy_exists;
		return $taxonomy_exists;
	}

	function wp_set_object_terms( int $post_id, array $terms, string $taxonomy, bool $append ): void {
		global $visibility_terms;
		if ( ! $append || ! isset( $visibility_terms[ $post_id ] ) ) {
			$visibility_terms[ $post_id ] = $terms;
			return;
		}
		$visibility_terms[ $post_id ] = array_values(
			array_unique( array_merge( $visibility_terms[ $post_id ], $terms ) )
		);
	}

	function wp_cache_delete( string $key, string $group ): bool {
		global $cache_deletes;
		$cache_deletes[] = array( $key, $group );
		return true;
	}
}

namespace PRC\Platform\Schema_SEO {
	class Metadata {
		public const CACHE_GROUP = 'prc_schema_seo_data_test';
	}
}

namespace PRC\Platform\Email_Builder {
	class Loader {
		public function add_action( string $hook, object $component, string $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	}

	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
	require_once dirname( __DIR__ ) . '/includes/class-auto-visibility.php';

	$auto_visibility = new Auto_Visibility( new Loader() );

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	function reset_globals(): void {
		global $test_meta, $updated_meta, $visibility_terms, $cache_deletes;
		$test_meta        = array();
		$updated_meta     = array();
		$visibility_terms = array();
		$cache_deletes    = array();
	}

	// Transactional (mandrill sub-mode): applies visibility terms and noindex.
	reset_globals();
	$post = new \WP_Post( 42, Post_Type::TRANSACTIONAL_POST_TYPE );
	$auto_visibility->maybe_apply_hide_flags( $post );
	assert_true(
		isset( $visibility_terms[42] )
		&& array( 'hidden-on-index', 'hidden-on-search' ) === $visibility_terms[42],
		'transactional post should set both visibility terms'
	);
	assert_true(
		! empty( $updated_meta[42]['_prc_seo_data']['noindex'] ),
		'transactional post should persist noindex'
	);
	assert_true(
		1 === count( $cache_deletes ),
		'transactional post should clear schema-seo cache once'
	);

	// Campaign post: no changes.
	reset_globals();
	$post = new \WP_Post( 44, Post_Type::CAMPAIGN_POST_TYPE );
	$auto_visibility->maybe_apply_hide_flags( $post );
	assert_true(
		! isset( $visibility_terms[44] ),
		'campaign post should not set visibility terms'
	);
	assert_true(
		! isset( $updated_meta[44] ),
		'campaign post should not update SEO meta'
	);

	// Additive: existing SEO noindex is not rewritten.
	reset_globals();
	$test_meta[ 46 ] = array(
		'_prc_seo_data' => array( 'noindex' => true, 'title' => 'Keep me' ),
	);
	$post            = new \WP_Post( 46, Post_Type::TRANSACTIONAL_POST_TYPE );
	$auto_visibility->maybe_apply_hide_flags( $post );
	assert_true(
		! isset( $updated_meta[46] ),
		'existing noindex should not trigger SEO meta update'
	);
	assert_true(
		0 === count( $cache_deletes ),
		'existing noindex should not clear cache again'
	);

	echo "OK: auto-visibility tests passed\n";
}
