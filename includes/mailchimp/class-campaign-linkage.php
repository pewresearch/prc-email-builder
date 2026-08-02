<?php
declare(strict_types=1);
/**
 * Read/clear Mailchimp campaign linkage meta on campaign posts.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Report_Store;

/**
 * Owns the three campaign-link meta keys plus Slack sent-marker cleanup.
 *
 * Does not clear audience, segment, template, subject, or preview text.
 */
final class Campaign_Linkage {

	public const META_CAMPAIGN_ID        = 'prc_email_mailchimp_campaign_id';
	public const META_CAMPAIGN_ADMIN_URL = 'prc_email_mailchimp_campaign_admin_url';
	public const META_CAMPAIGN_STATUS    = 'prc_email_mailchimp_campaign_status';

	/**
	 * @return array{campaign_id: string, admin_url: string, status: string}
	 */
	public static function read( int $post_id ): array {
		return [
			'campaign_id' => (string) get_post_meta( $post_id, self::META_CAMPAIGN_ID, true ),
			'admin_url'   => (string) get_post_meta( $post_id, self::META_CAMPAIGN_ADMIN_URL, true ),
			'status'      => (string) get_post_meta( $post_id, self::META_CAMPAIGN_STATUS, true ),
		];
	}

	public static function is_linked( int $post_id ): bool {
		return '' !== self::read( $post_id )['campaign_id'];
	}

	/**
	 * Clears linkage + Slack marker and report meta. Idempotent.
	 *
	 * @return array{
	 *   campaign_id: string,
	 *   admin_url: string,
	 *   status: string,
	 *   had_report: bool
	 * }
	 */
	public static function clear( int $post_id ): array {
		$prior      = self::read( $post_id );
		$had_report = null !== Report_Store::get_report( $post_id )
			|| '' !== Report_Store::get_sync_state( $post_id );

		delete_post_meta( $post_id, self::META_CAMPAIGN_ID );
		delete_post_meta( $post_id, self::META_CAMPAIGN_ADMIN_URL );
		delete_post_meta( $post_id, self::META_CAMPAIGN_STATUS );
		delete_post_meta( $post_id, Campaign_Status_Sync::SENT_SLACK_NOTIFIED_META );

		Report_Store::clear( $post_id );

		return array_merge( $prior, [ 'had_report' => $had_report ] );
	}
}
