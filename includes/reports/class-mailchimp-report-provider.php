<?php
/**
 * Mailchimp Reports API provider.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder\Reports;

use PRC\Platform\Email_Builder\Mailchimp;
use WP_Error;

/**
 * Pulls aggregate campaign reports from Mailchimp (no member endpoints).
 */
class Mailchimp_Report_Provider implements Report_Provider {

	/**
	 * Channel.
	 */
	public function channel(): string {
		return Report_Schema::CHANNEL_MAILCHIMP;
	}

	/**
	 * Fetch.
	 *
	 * @param string $external_id Mailchimp campaign ID.
	 */
	public function fetch( string $external_id ): array|WP_Error {
		if ( '' === $external_id ) {
			return new WP_Error( 'report_missing_campaign_id', 'Mailchimp campaign ID is required.' );
		}

		$mailchimp = new Mailchimp();
		$report    = $mailchimp->get_campaign_report( $external_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$click_details = $mailchimp->get_campaign_click_details( $external_id );
		$click_array   = array();
		if ( ! is_wp_error( $click_details ) ) {
			$click_array = $click_details;
		}

		return Report_Schema::normalize_mailchimp( $report, $click_array );
	}
}
