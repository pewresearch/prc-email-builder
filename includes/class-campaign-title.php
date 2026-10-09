<?php
/**
 * Front-end headline for newsletter campaigns.
 *
 * The post title stays the internal campaign name (wp-admin, Mailchimp). Readers
 * see the subject line instead, with merge tags removed.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Resolve and apply the campaign subject line as the public headline.
 */
class Campaign_Title {
	/** Post meta key that stores a campaign's saved SEO data. */
	public const SEO_META_KEY = '_prc_seo_data';

	/**
	 * Construct.
	 *
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_filter( 'the_title', $this, 'filter_single_campaign_title', 10, 2 );
		// Priority 5 runs before core's strip_tags (10), ent2ncr (8) and esc_html (10) on this filter.
		$loader->add_filter( 'the_title_rss', $this, 'filter_feed_item_title', 5 );
		$loader->add_filter( 'get_wp_title_rss', $this, 'filter_feed_channel_title' );
		$loader->add_action( 'wp_head', $this, 'render_list_feed_link', 3 );

		// Priority 5 runs before the SEO title pattern (9) so the pattern wraps the subject.
		$loader->add_filter( 'prc_schema_seo_title', $this, 'filter_seo_title', 5, 2 );
		$loader->add_filter( 'prc_schema_seo_og_title', $this, 'filter_seo_og_title', 10, 2 );
		$loader->add_filter( 'prc_schema_seo_schema_title', $this, 'filter_seo_title', 10, 2 );

		$loader->add_filter( 'prc_schema_seo_cache_meta_keys', $this, 'add_subject_cache_meta_key' );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			$loader->add_action( $hook, $this, 'refresh_story_item_cache_on_subject_change', 10, 4 );
		}
	}

	/**
	 * Remove Mailchimp (`*|TAG|*`) and Mandrill/handlebars (`{{tag}}`) merge tags.
	 *
	 * Display only. The saved subject is never changed. Whitespace left behind is
	 * collapsed and separators left at either end are trimmed, so
	 * "*|FNAME|*, your update" becomes "your update".
	 *
	 * @param string $text Subject line.
	 * @return string
	 */
	public static function strip_merge_tags( string $text ): string {
		$stripped = preg_replace( '/\*\|[^|*]*\|\*/', '', $text ) ?? $text;
		$stripped = preg_replace( '/\{\{[^{}]*\}\}/', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+/u', ' ', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+([,;:.!?])/u', '$1', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/^[\s,;:\-\x{2013}\x{2014}|\x{00B7}\x{2022}\/]+|[\s,;:\-\x{2013}\x{2014}|\x{00B7}\x{2022}\/]+$/u', '', $stripped ) ?? $stripped;
		return trim( $stripped );
	}

	/**
	 * Public headline for a campaign, or null when the post title should be used.
	 *
	 * Returns null for non-campaigns, an empty subject, and a subject that is only merge tags.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public static function for_display( int $post_id ): ?string {
		if ( $post_id <= 0 || Post_Type::CAMPAIGN_POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}

		$ready = Email_Subject::parse( get_post_meta( $post_id, Email_Subject::META_KEY, true ) );
		if ( null === $ready ) {
			return null;
		}

		$headline = self::strip_merge_tags( $ready->line() );
		return '' === $headline ? null : $headline;
	}

	/**
	 * Use the subject line as the heading on a campaign's own front-end page.
	 *
	 * Scoped to the queried campaign so admin, REST, cron, and Mailchimp keep the post title.
	 *
	 * @hook the_title
	 *
	 * @param mixed $title   Post title.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public function filter_single_campaign_title( $title, $post_id = 0 ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() ) {
			return $title;
		}
		if ( ! is_singular( Post_Type::CAMPAIGN_POST_TYPE ) || get_queried_object_id() !== (int) $post_id ) {
			return $title;
		}

		$headline = self::for_display( (int) $post_id );
		return null === $headline ? $title : esc_html( $headline );
	}

	/**
	 * Use the subject line as the RSS item title for campaigns.
	 *
	 * @hook the_title_rss
	 *
	 * @param mixed $title Feed item title.
	 * @return mixed
	 */
	public function filter_feed_item_title( $title ) {
		$headline = self::for_display( (int) get_the_ID() );
		return null === $headline ? $title : wptexturize( $headline );
	}

	/**
	 * Name the channel after the newsletter list on list feeds.
	 *
	 * @hook get_wp_title_rss
	 *
	 * @param mixed $title Channel title.
	 * @return mixed
	 */
	public function filter_feed_channel_title( $title ) {
		if ( ! is_feed() || ! is_tax( Post_Type::TAXONOMY ) ) {
			return $title;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return $title;
		}

		/* translators: %s: newsletter list name. */
		return ent2ncr( esc_html( sprintf( __( 'Newsletter: %s', 'prc-email-builder' ), $term->name ) ) );
	}

	/**
	 * Emit RSS autodiscovery on newsletter list archives.
	 *
	 * Core's taxonomy feed links are removed site-wide, so this adds back only the list feed.
	 *
	 * @hook wp_head
	 */
	public function render_list_feed_link(): void {
		if ( ! is_tax( Post_Type::TAXONOMY ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		$href = get_term_feed_link( $term->term_id, Post_Type::TAXONOMY );
		if ( ! is_string( $href ) || '' === $href ) {
			return;
		}

		printf(
			'<link rel="alternate" type="application/rss+xml" title="%1$s" href="%2$s" />' . "\n",
			/* translators: %s: newsletter list name. */
			esc_attr( sprintf( __( 'Newsletter: %s', 'prc-email-builder' ), $term->name ) ),
			esc_url( $href )
		);
	}

	/**
	 * Use the subject line for the SEO and structured-data title of a campaign.
	 *
	 * A title an editor saved in the SEO panel wins over the subject.
	 *
	 * @hook prc_schema_seo_title
	 * @hook prc_schema_seo_schema_title
	 *
	 * @param mixed $title   Resolved SEO title.
	 * @param int   $post_id Post ID.
	 * @return mixed
	 */
	public function filter_seo_title( $title, $post_id ) {
		return $this->subject_unless_saved( $title, (int) $post_id, 'title' );
	}

	/**
	 * Use the subject line for the social (Open Graph) title of a campaign.
	 *
	 * A social title an editor saved in the SEO panel wins over the subject.
	 *
	 * @hook prc_schema_seo_og_title
	 *
	 * @param mixed $title   Resolved social title.
	 * @param int   $post_id Post ID.
	 * @return mixed
	 */
	public function filter_seo_og_title( $title, $post_id ) {
		return $this->subject_unless_saved( $title, (int) $post_id, 'og_title' );
	}

	/**
	 * Let schema-seo flush its caches when a campaign's subject changes.
	 *
	 * @hook prc_schema_seo_cache_meta_keys
	 *
	 * @param mixed $keys Meta keys that clear the SEO cache.
	 * @return array<int, string>
	 */
	public function add_subject_cache_meta_key( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = Email_Subject::META_KEY;
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Rebuild cached story-item markup when only the subject changes.
	 *
	 * @hook added_post_meta
	 * @hook updated_post_meta
	 * @hook deleted_post_meta
	 *
	 * @param mixed  $meta_id    Meta ID or IDs.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public function refresh_story_item_cache_on_subject_change( $meta_id, $object_id, $meta_key, $meta_value = null ): void {
		unset( $meta_id, $meta_value );
		if ( Email_Subject::META_KEY !== $meta_key ) {
			return;
		}
		if ( ! class_exists( '\PRC\Platform\Blocks\Story_Item' ) || ! method_exists( '\PRC\Platform\Blocks\Story_Item', 'bump_cache_version' ) ) {
			return;
		}
		\PRC\Platform\Blocks\Story_Item::bump_cache_version( (int) $object_id );
	}

	/**
	 * Return the campaign subject unless the editor saved their own value.
	 *
	 * @param mixed  $current Current resolved value.
	 * @param int    $post_id Post ID.
	 * @param string $field   SEO data field: `title` or `og_title`.
	 * @return mixed
	 */
	private function subject_unless_saved( $current, int $post_id, string $field ) {
		$headline = self::for_display( $post_id );
		if ( null === $headline ) {
			return $current;
		}

		$saved = get_post_meta( $post_id, self::SEO_META_KEY, true );
		if ( is_array( $saved ) && isset( $saved[ $field ] ) && '' !== trim( (string) $saved[ $field ] ) ) {
			return $current;
		}

		return $headline;
	}
}
