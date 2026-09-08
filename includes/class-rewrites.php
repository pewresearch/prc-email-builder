<?php
/**
 * Newsletter list and campaign permalink routing.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Pretty URLs for prc_newsletter_list archives and prc_email_campaign singles.
 *
 * List archive:  /newsletter/{list-slug}/
 * Campaign single: /newsletter/{list-slug}/{campaign-slug}/
 */
class Rewrites {
	const NEWSLETTER_URL_PREFIX = 'newsletter';

	/** Static list segment when a campaign has no assigned newsletter list term. */
	const LISTLESS_FALLBACK_SLUG = 'campaign';

	const REWRITE_FLUSH_OPTION  = 'prc_email_builder_rewrite_version';
	const REWRITE_FLUSH_VERSION = 1;

	/**
	 * Construct.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_filter( 'post_type_link', $this, 'filter_campaign_permalink', 10, 2 );
		$loader->add_action( 'template_redirect', $this, 'redirect_canonical_list_segment' );
		$loader->add_filter( 'prc_research_teams_excluded_url_slugs', $this, 'exclude_newsletter_slug' );
		$loader->add_action( 'pre_get_posts', $this, 'restrict_list_archive_to_campaigns' );
		$loader->add_action( 'admin_init', $this, 'maybe_flush_rewrite_rules', 99999 );
	}

	/**
	 * Replace the %prc_newsletter_list% rewrite placeholder with the assigned term slug.
	 *
	 * @param string   $post_link Campaign permalink with placeholder.
	 * @param \WP_Post $post      Campaign post.
	 * @return string
	 */
	public function filter_campaign_permalink( string $post_link, \WP_Post $post ): string {
		if ( Post_Type::CAMPAIGN_POST_TYPE !== $post->post_type ) {
			return $post_link;
		}

		$placeholder = '%' . Post_Type::TAXONOMY . '%';
		if ( false === strpos( $post_link, $placeholder ) ) {
			return $post_link;
		}

		return str_replace( $placeholder, self::get_list_slug_for_post( (int) $post->ID ), $post_link );
	}

	/**
	 * 301 to the canonical permalink when the URL list segment does not match.
	 *
	 * Keeps old "View in browser" links working after a campaign's list term changes.
	 */
	public function redirect_canonical_list_segment(): void {
		if ( ! is_singular( Post_Type::CAMPAIGN_POST_TYPE ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$canonical = get_permalink( $post_id );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return;
		}

		global $wp;

		$requested_path = wp_parse_url( user_trailingslashit( home_url( $wp->request ) ), PHP_URL_PATH );
		$canonical_path = wp_parse_url( user_trailingslashit( $canonical ), PHP_URL_PATH );

		if (
			! is_string( $requested_path )
			|| ! is_string( $canonical_path )
			|| $requested_path === $canonical_path
		) {
			return;
		}

		wp_safe_redirect( $canonical, 301 );
		exit;
	}

	/**
	 * Prevent research-team wildcard rules from capturing /newsletter/... URLs.
	 *
	 * @param array $excluded Slugs excluded from research-team URL matching.
	 * @return array
	 */
	public function exclude_newsletter_slug( array $excluded ): array {
		$excluded[] = self::NEWSLETTER_URL_PREFIX;
		return array_values( array_unique( $excluded ) );
	}

	/**
	 * Keep list archives on campaigns after block_module also uses this taxonomy.
	 *
	 * Taxonomy archives query every attached public post type. Without this,
	 * Block Modules assigned to a list appear in the campaign listing.
	 *
	 * @param \WP_Query $query Main or secondary query.
	 */
	public function restrict_list_archive_to_campaigns( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_tax( Post_Type::TAXONOMY ) ) {
			return;
		}
		$query->set( 'post_type', Post_Type::CAMPAIGN_POST_TYPE );
	}

	/**
	 * One-time rewrite flush after permalink structure changes.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( (string) get_option( self::REWRITE_FLUSH_OPTION ) === (string) self::REWRITE_FLUSH_VERSION ) {
			return;
		}

		flush_rewrite_rules( false ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- one-time versioned flush after permalink structure changes.
		update_option( self::REWRITE_FLUSH_OPTION, (string) self::REWRITE_FLUSH_VERSION, false );
	}

	/**
	 * Returns the newsletter list slug embedded in a campaign permalink.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return string List term slug, or the listless fallback segment.
	 */
	public static function get_list_slug_for_post( int $post_id ): string {
		$terms = wp_get_object_terms(
			$post_id,
			Post_Type::TAXONOMY,
			array(
				'fields' => 'all',
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && isset( $terms[0]->slug ) ) {
			return (string) $terms[0]->slug;
		}

		return self::LISTLESS_FALLBACK_SLUG;
	}
}
