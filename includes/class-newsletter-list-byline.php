<?php
/**
 * Newsletter list enforced byline: attribute every campaign on a list to one staff byline.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Staff_Bylines\Byline_Sync;

/**
 * A newsletter list can name one `bylines` term. Campaigns on that list are
 * attributed to it as an acknowledgement, so they join that staff page without
 * printing a byline in the email, and are cleared from `hidden-on-index` so the
 * staff page publication listing includes them.
 */
class Newsletter_List_Byline {

	/** Term meta: `bylines` term ID attributed to every campaign on the list. Empty = none. */
	const META_KEY = 'prc_newsletter_list_enforced_byline_term_id';

	/** Staff bylines taxonomy. The staff plugin owns it; this plugin does not load that plugin. */
	const TAXONOMY = 'bylines';

	/** `_post_visibility` taxonomy and the term that keeps a post off publication listings. */
	const VISIBILITY_TAXONOMY  = '_post_visibility';
	const HIDDEN_ON_INDEX_TERM = 'hidden-on-index';

	/** Outcomes of apply(). */
	const RESULT_ENFORCED    = 'enforced';
	const RESULT_CURRENT     = 'current';
	const RESULT_NO_LIST     = 'no-list';
	const RESULT_NO_BYLINE   = 'no-byline';
	const RESULT_UNAVAILABLE = 'unavailable';

	/**
	 * Wire campaign save hooks.
	 *
	 * @param Loader $loader Plugin loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_after_insert_' . Post_Type::CAMPAIGN_POST_TYPE, $this, 'enforce_on_rest_save', 10, 1 );
		$loader->add_action( 'wp_after_insert_post', $this, 'enforce_on_save', 20, 2 );
	}

	/**
	 * Sanitize a bylines term ID; empty or non-positive becomes ''.
	 *
	 * @param mixed $raw Raw meta value.
	 */
	public static function sanitize_term_id( mixed $raw ): string {
		$id = absint( $raw );
		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Enforce the list byline after the block editor saves a campaign.
	 *
	 * Runs after the list audience override (priority 9).
	 *
	 * @hook rest_after_insert_prc_email_campaign (priority 10)
	 *
	 * @param \WP_Post $post Saved campaign post.
	 */
	public function enforce_on_rest_save( \WP_Post $post ): void {
		self::apply( $post->ID );
	}

	/**
	 * Enforce the list byline after classic and CLI saves.
	 *
	 * Runs after publication-listing applies its default `hidden-on-index`
	 * visibility to new posts, so the list byline can clear it.
	 *
	 * @hook wp_after_insert_post (priority 20)
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public function enforce_on_save( int $post_id, \WP_Post $post ): void {
		if ( ! Post_Type::is_campaign_post( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::apply( $post_id );
	}

	/**
	 * Enforced `bylines` term for a list, or 0 when unset or no longer a byline term.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 */
	public static function resolve_term_id( int $list_term_id ): int {
		if ( $list_term_id <= 0 ) {
			return 0;
		}

		$byline_term_id = absint( self::sanitize_term_id( get_term_meta( $list_term_id, self::META_KEY, true ) ) );
		if ( $byline_term_id <= 0 ) {
			return 0;
		}

		return get_term( $byline_term_id, self::TAXONOMY ) instanceof \WP_Term ? $byline_term_id : 0;
	}

	/**
	 * Attribute a campaign to its list's enforced byline.
	 *
	 * Stores the byline as an acknowledgement and clears `hidden-on-index`.
	 * Safe to run repeatedly: a campaign that is already attributed and visible
	 * is left untouched.
	 *
	 * @param int  $post_id Campaign post ID.
	 * @param bool $dry_run Report the outcome without writing.
	 * @return string One of the RESULT_* constants.
	 */
	public static function apply( int $post_id, bool $dry_run = false ): string {
		$list_term_id = Newsletter_List::resolve_mailchimp_targeting( $post_id )['term_id'];
		if ( $list_term_id <= 0 ) {
			return self::RESULT_NO_LIST;
		}

		$byline_term_id = self::resolve_term_id( $list_term_id );
		if ( $byline_term_id <= 0 ) {
			return self::RESULT_NO_BYLINE;
		}

		if ( ! class_exists( Byline_Sync::class ) ) {
			return self::RESULT_UNAVAILABLE;
		}

		$is_attributed = Byline_Sync::is_attributed( $post_id, $byline_term_id );
		$is_hidden     = has_term( self::HIDDEN_ON_INDEX_TERM, self::VISIBILITY_TAXONOMY, $post_id );
		if ( $is_attributed && ! $is_hidden ) {
			return self::RESULT_CURRENT;
		}

		if ( ! $dry_run ) {
			if ( ! $is_attributed ) {
				Byline_Sync::add_term_row( $post_id, $byline_term_id );
			}
			if ( $is_hidden ) {
				wp_remove_object_terms( $post_id, self::HIDDEN_ON_INDEX_TERM, self::VISIBILITY_TAXONOMY );
			}
		}

		return self::RESULT_ENFORCED;
	}
}
