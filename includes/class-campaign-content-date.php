<?php
/**
 * Web-hidden post date blocks inside email campaign content.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use function PRC\Primitives\BlockUtils\strip_block_from_post_content;

/**
 * Hides core/post-date blocks rendered inside a campaign's post content.
 *
 * The single campaign template already prints the date. A date block in the
 * content exists for the email, which converts blocks without calling
 * render_block() for core/post-date, so this rule never touches email output.
 */
class Campaign_Content_Date {

	/**
	 * Register the strip rule.
	 */
	public static function init(): void {
		strip_block_from_post_content( 'core/post-date', array( self::class, 'is_campaign' ) );
	}

	/**
	 * Whether the post being rendered is an email campaign.
	 *
	 * @param array<string, mixed> $block   Parsed block.
	 * @param int                  $post_id ID of the post whose content is rendering.
	 * @return bool
	 */
	public static function is_campaign( array $block, int $post_id ): bool {
		return Post_Type::CAMPAIGN_POST_TYPE === get_post_type( $post_id );
	}
}
