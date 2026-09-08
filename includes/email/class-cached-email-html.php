<?php
/**
 * Resolves email HTML for newsletter sends.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

/**
 * Shared helper for Mandrill send flows (REST, WP-CLI).
 *
 * Email HTML is rendered synchronously via Email_Block_Converter.
 */
class Cached_Email_Html {

	/**
	 * Returns wrapped email HTML from the deterministic block converter.
	 *
	 * @param int $post_id Newsletter post ID.
	 * @return string|WP_Error Full email HTML document, or WP_Error.
	 */
	public static function resolve( int $post_id ): string|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				sprintf( 'Post %d not found.', $post_id )
			);
		}

		$converter = new Email_Block_Converter();
		$content   = $converter->convert( $post );

		if ( '' === $content ) {
			return new WP_Error(
				'empty_content',
				sprintf( 'Newsletter post %d has no renderable block content.', $post_id )
			);
		}

		return Email_Template::wrap( $content, $post_id );
	}
}
