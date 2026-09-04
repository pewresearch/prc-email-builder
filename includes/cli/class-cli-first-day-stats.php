<?php
/**
 * WP-CLI command for the Mailchimp first-day Slack follow-up.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_CLI;

/**
 * Run the first-day Mailchimp Slack follow-up for a campaign now.
 */
class CLI_First_Day_Stats {

	/**
	 * Post the Mailchimp first-24-hours reply in the campaign's Slack thread.
	 *
	 * Still production-gated. Use this to run the job without waiting 24 hours.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : Campaign post ID that Mailchimp has already marked sent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email first-day-stats 12345
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function __invoke( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			WP_CLI::error( 'A valid campaign post ID is required.' );
		}

		$campaign_id = (string) get_post_meta( $post_id, Campaign_Linkage::META_CAMPAIGN_ID, true );
		if ( '' === $campaign_id ) {
			WP_CLI::error( 'This post has no Mailchimp campaign ID.' );
		}

		First_Day_Campaign_Stats::handle( $post_id, $campaign_id, 1 );
		WP_CLI::success( sprintf( 'First-day Mailchimp stats handler ran for post %d.', $post_id ) );
	}
}
