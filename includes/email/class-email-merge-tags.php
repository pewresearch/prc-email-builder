<?php
declare(strict_types=1);
/**
 * Resolves view-online merge tags to the website campaign permalink.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Substitutes Mailchimp and legacy NGL "view online" tags with get_permalink().
 *
 * Mailchimp list-management tags (*|UNSUB|*, *|UPDATE_PROFILE|*) are left
 * untouched so Mailchimp can replace them at send time.
 */
class Email_Merge_Tags {

	/** Tags replaced with the campaign website permalink. */
	private const VIEW_ONLINE_TAGS = [
		'*|ARCHIVE|*',
		'{{ blog_post }}',
		'{{ webversion }}',
	];

	/**
	 * Replace view-online merge tags with the post permalink.
	 *
	 * @param string $html    Full email HTML document.
	 * @param int    $post_id Newsletter post ID.
	 * @return string HTML with view-online tags resolved.
	 */
	public static function resolve( string $html, int $post_id ): string {
		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return $html;
		}

		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return $html;
		}

		$url = esc_url( $permalink );

		return str_replace( self::VIEW_ONLINE_TAGS, $url, $html );
	}

	/**
	 * Returns the view-online URL for a campaign post.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string Escaped permalink, or empty string when unavailable.
	 */
	public static function get_view_online_url( int $post_id ): string {
		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return '';
		}

		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}

		return esc_url( $permalink );
	}
}
