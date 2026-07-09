<?php
declare(strict_types=1);
/**
 * Contract for fetching and normalizing email engagement reports.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder\Reports;

use WP_Error;

/**
 * Fetches provider-specific engagement data and returns a normalized report shape.
 */
interface Report_Provider {

	/**
	 * Provider channel slug (e.g. mailchimp, mandrill).
	 */
	public function channel(): string;

	/**
	 * Fetch and normalize a report for the provider's external send identifier.
	 *
	 * @param string $external_id Provider send ID (Mailchimp campaign ID).
	 * @return array<string, mixed>|WP_Error Normalized report per Report_Schema.
	 */
	public function fetch( string $external_id ): array|WP_Error;
}
