<?php
declare(strict_types=1);
/**
 * Auto-applies platform hide flags to transactional emails.
 *
 * Transactional emails are not public web content;
 * they are hidden from the publications archive, internal search, and search
 * engines on save.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Applies publication-listing visibility terms and SEO noindex for transactional posts.
 */
class Auto_Visibility {

	/**
	 * Taxonomy used by prc-publication-listing for archive/search visibility.
	 */
	private const VISIBILITY_TAXONOMY = '_post_visibility';

	/**
	 * Term slugs that hide a post from the publications archive and internal search.
	 */
	private const HIDE_TERMS = array( 'hidden-on-index', 'hidden-on-search' );

	/**
	 * SEO meta key used by prc-schema-seo.
	 */
	private const SEO_META_KEY = '_prc_seo_data';

	/**
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action(
			'rest_after_insert_' . Post_Type::TRANSACTIONAL_POST_TYPE,
			$this,
			'maybe_apply_hide_flags',
			20,
			1
		);
	}

	/**
	 * Applies hide flags when a transactional email is saved.
	 *
	 * Additive only: never removes visibility or noindex on campaign posts.
	 *
	 * @hook rest_after_insert_{post_type}
	 *
	 * @param \WP_Post $post Saved transactional email post.
	 */
	public function maybe_apply_hide_flags( \WP_Post $post ): void {
		if ( ! Post_Type::is_transactional_post( $post ) ) {
			return;
		}

		$this->apply_post_visibility_terms( $post->ID );
		$this->apply_seo_noindex( $post->ID );
	}

	/**
	 * Appends hidden-on-index and hidden-on-search to _post_visibility.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private function apply_post_visibility_terms( int $post_id ): void {
		if ( ! taxonomy_exists( self::VISIBILITY_TAXONOMY ) ) {
			return;
		}

		wp_set_object_terms( $post_id, self::HIDE_TERMS, self::VISIBILITY_TAXONOMY, true );
	}

	/**
	 * Persists noindex in _prc_seo_data without clobbering other SEO fields.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private function apply_seo_noindex( int $post_id ): void {
		$seo = get_post_meta( $post_id, self::SEO_META_KEY, true );
		$seo = is_array( $seo ) ? $seo : array();

		if ( ! empty( $seo['noindex'] ) ) {
			return;
		}

		$seo['noindex'] = true;
		update_post_meta( $post_id, self::SEO_META_KEY, $seo );
		$this->clear_schema_seo_cache( $post_id );
	}

	/**
	 * Clears prc-schema-seo object cache for the post when that plugin is active.
	 *
	 * @param int $post_id Newsletter post ID.
	 */
	private function clear_schema_seo_cache( int $post_id ): void {
		if ( ! class_exists( '\PRC\Platform\Schema_SEO\Metadata' ) ) {
			return;
		}

		wp_cache_delete(
			'seo_data_' . $post_id,
			\PRC\Platform\Schema_SEO\Metadata::CACHE_GROUP
		);
	}
}
