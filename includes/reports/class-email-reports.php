<?php
/**
 * Public reporting surface for campaign and transactional emails.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder\Reports;

use PRC\Platform\Email_Builder\Mandrill_Event_Ledger;
use PRC\Platform\Email_Builder\Mandrill_Send_Key;
use PRC\Platform\Email_Builder\System_Email_Recipients_Table;
use WP_Error;

/**
 * The only reporting surface in the plugin.
 *
 * Reads store meta, then applies `prc_email_builder_report_envelope`. Does not
 * call Report_Store::envelope(), so there is no loop.
 */
class Email_Reports {

	/**
	 * Report for the inspector panel and the DataView stats modal.
	 *
	 * Never fails and never performs a provider HTTP request.
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>
	 */
	public static function envelope( int $post_id ): array {
		$post            = get_post( $post_id );
		$channel         = Channel::for_post( $post );
		$stats_available = Channel::stats_available( $post );
		$built           = match ( $channel ) {
			Channel::Mailchimp    => self::mailchimp_envelope( $post_id, $stats_available ),
			Channel::MandrillBulk => self::mandrill_bulk_envelope( $post_id, $stats_available ),
			Channel::System       => self::system_envelope( $post_id, $stats_available ),
		};

		$coverage = $built['coverage'];
		$report   = $built['report'];
		$rates    = self::rates_from_report( $report, $coverage );

		$envelope = array(
			'report'           => $report,
			'sync_state'       => Report_Store::get_sync_state( $post_id ),
			'last_synced'      => Report_Store::get_last_synced( $post_id ),
			'open_rate'        => $rates['open_rate'],
			'click_rate'       => $rates['click_rate'],
			'mailchimp_status' => (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', true ),
			'delivery_status'  => Channel::delivery_status( $post ),
			'channel'          => $channel->value,
			'stats_available'  => $stats_available,
			'coverage'         => $coverage->to_array(),
		);

		return apply_filters( 'prc_email_builder_report_envelope', $envelope, $post_id, $post );
	}

	/**
	 * User-initiated refresh. Campaigns pull Mailchimp; txn recomputes from ledger + volume.
	 *
	 * @param int  $post_id        Email post ID.
	 * @param bool $skip_throttle  When true, skip the min-interval (scheduled workers).
	 * @return array<string, mixed>|WP_Error Envelope on success.
	 */
	public static function refresh( int $post_id, bool $skip_throttle = false ): array|WP_Error {
		$post    = get_post( $post_id );
		$channel = Channel::for_post( $post );

		if ( Channel::Mailchimp === $channel ) {
			return Report_Sync::refresh_now( $post_id );
		}

		if ( ! $post instanceof \WP_Post || ! Channel::stats_available( $post ) ) {
			return new WP_Error(
				'report_refresh_not_eligible',
				'This email is not eligible for report refresh.',
				array( 'status' => 400 )
			);
		}

		if ( ! $skip_throttle ) {
			$last = Report_Store::get_last_synced( $post_id );
			if ( '' !== $last ) {
				$last_ts = strtotime( $last );
				if ( $last_ts && ( time() - $last_ts ) < Report_Schema::refresh_min_interval() ) {
					return new WP_Error(
						'report_refresh_throttled',
						'Report was refreshed recently. Please wait before refreshing again.',
						array( 'status' => 429 )
					);
				}
			}
		}

		$normalized = Channel::System === $channel
			? self::system_report( $post_id )
			: self::mandrill_bulk_report( $post_id );
		Report_Store::save_report( $post_id, $normalized );

		return self::envelope( $post_id );
	}

	/**
	 * One DataView row's engagement fields. Reads persisted meta only.
	 *
	 * @param int $post_id Email post ID.
	 * @return array{stats_available: bool, open_rate: float|null, click_rate: float|null, channel: string}
	 */
	public static function row_stats( int $post_id ): array {
		$post    = get_post( $post_id );
		$channel = Channel::for_post( $post );

		return array(
			'stats_available' => Channel::stats_available( $post ),
			'open_rate'       => self::meta_rate( $post_id, Report_Store::META_OPEN_RATE ),
			'click_rate'      => self::meta_rate( $post_id, Report_Store::META_CLICK_RATE ),
			'channel'         => $channel->value,
		);
	}

	/**
	 * Mailchimp envelope: stored report plus full-audience coverage when sent.
	 *
	 * @param int  $post_id         Email post ID.
	 * @param bool $stats_available Channel send gate.
	 * @return array{report: array<string, mixed>|null, coverage: Coverage}
	 */
	private static function mailchimp_envelope( int $post_id, bool $stats_available ): array {
		$report = Report_Store::get_report( $post_id );
		if ( is_array( $report ) || $stats_available ) {
			$coverage = new Coverage(
				Coverage::VOLUME_COMPLETE,
				Coverage::ENGAGEMENT_FULL,
				Coverage::AUDIENCE_FULL
			);
		} else {
			$coverage = new Coverage(
				Coverage::VOLUME_NONE,
				Coverage::ENGAGEMENT_NONE,
				Coverage::AUDIENCE_FULL
			);
		}

		return array(
			'report'   => $report,
			'coverage' => $coverage,
		);
	}

	/**
	 * Mandrill bulk envelope: send-summary volume plus ledger engagement.
	 *
	 * @param int  $post_id         Email post ID.
	 * @param bool $stats_available Channel send gate.
	 * @return array{report: array<string, mixed>|null, coverage: Coverage}
	 */
	private static function mandrill_bulk_envelope( int $post_id, bool $stats_available ): array {
		$report = self::mandrill_bulk_report( $post_id );
		return array(
			'report'   => $report,
			'coverage' => self::coverage_from_report( $report, $stats_available ),
		);
	}

	/**
	 * System-email envelope: recipients-table volume plus ledger engagement.
	 *
	 * @param int  $post_id         Email post ID.
	 * @param bool $stats_available Channel send gate.
	 * @return array{report: array<string, mixed>|null, coverage: Coverage}
	 */
	private static function system_envelope( int $post_id, bool $stats_available ): array {
		$report = self::system_report( $post_id );
		return array(
			'report'   => $report,
			'coverage' => self::coverage_from_report( $report, $stats_available ),
		);
	}

	/**
	 * Normalized Mandrill bulk report (volume from send summary, engagement from ledger).
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>
	 */
	private static function mandrill_bulk_report( int $post_id ): array {
		$decoded = self::mandrill_send_summary( $post_id );
		$volume  = (int) ( $decoded['sent'] ?? 0 ) + (int) ( $decoded['queued'] ?? 0 );
		$since   = Mandrill_Send_Key::since( $post_id );
		$sent_at = isset( $decoded['sent_at'] ) && is_string( $decoded['sent_at'] )
			? $decoded['sent_at']
			: '';
		return self::txn_report( $post_id, Channel::MandrillBulk, $volume, $since, $sent_at );
	}

	/**
	 * Normalized system-email report (volume from recipients table, engagement from ledger).
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>
	 */
	private static function system_report( int $post_id ): array {
		$volume = System_Email_Recipients_Table::count_for_post( $post_id );
		$since  = Mandrill_Send_Key::since( $post_id );
		return self::txn_report( $post_id, Channel::System, $volume, $since );
	}

	/**
	 * Normalized transactional report shared by Mandrill bulk and system email.
	 *
	 * @param int     $post_id   Email post ID.
	 * @param Channel $channel   Send path.
	 * @param int     $volume    Accepted / distinct recipient count.
	 * @param string  $since     Identity-since ISO timestamp, or empty.
	 * @param string  $send_time Refresh-window anchor, or empty.
	 * @return array<string, mixed>
	 */
	private static function txn_report( int $post_id, Channel $channel, int $volume, string $since, string $send_time = '' ): array {

		$has_identity = '' !== $since;
		$coverage     = new Coverage(
			$volume > 0 ? Coverage::VOLUME_COMPLETE : Coverage::VOLUME_NONE,
			$has_identity ? Coverage::ENGAGEMENT_FORWARD_ONLY : Coverage::ENGAGEMENT_NONE,
			Coverage::AUDIENCE_FULL,
			$has_identity ? $since : null,
			null
		);

		$summary = array(
			'emails_sent' => $volume,
		);

		$clicks = array();
		$agg    = array();
		if ( $has_identity && $post_id > 0 ) {
			$since_mysql = self::iso_to_mysql( $since );
			$agg         = Mandrill_Event_Ledger::aggregate( $post_id, $since_mysql );
			$tracked     = (int) ( $agg['tracked_recipients'] ?? 0 );
			$coverage    = new Coverage(
				$volume > 0 ? Coverage::VOLUME_COMPLETE : Coverage::VOLUME_NONE,
				Coverage::ENGAGEMENT_FORWARD_ONLY,
				Coverage::AUDIENCE_FULL,
				$since,
				$tracked
			);
			$summary     = self::summary_from_ledger( $volume, $agg, true );
			$clicks      = $agg['clicks_by_url'] ?? array();
		} else {
			$summary['open_rate']  = null;
			$summary['click_rate'] = null;
		}

		return array(
			'channel'       => $channel->value,
			'summary'       => $summary,
			'clicks_by_url' => is_array( $clicks ) ? $clicks : array(),
			'send_time'     => $send_time,
			'coverage'      => $coverage->to_array(),
		);
	}

	/**
	 * Decoded Mandrill send summary for a post.
	 *
	 * @param int $post_id Email post ID.
	 * @return array<string, mixed>
	 */
	private static function mandrill_send_summary( int $post_id ): array {
		$raw = get_post_meta( $post_id, 'prc_email_mandrill_send_summary', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			$decoded = array();
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Summary fields from ledger uniques. Rates stay null when untracked.
	 *
	 * @param int                  $volume  Full-audience volume.
	 * @param array<string, mixed> $agg     Ledger aggregate.
	 * @param bool                 $tracked Whether engagement is observed.
	 * @return array<string, mixed>
	 */
	private static function summary_from_ledger( int $volume, array $agg, bool $tracked ): array {
		$summary = array(
			'emails_sent' => $volume,
		);
		if ( ! $tracked ) {
			$summary['open_rate']  = null;
			$summary['click_rate'] = null;
			return $summary;
		}

		$opens_unique  = (int) ( $agg['opens_unique'] ?? 0 );
		$clicks_unique = (int) ( $agg['clicks_unique'] ?? 0 );
		$bounces       = (int) ( $agg['bounces'] ?? 0 );
		$denom         = $volume;
		if ( $bounces > 0 && $denom > $bounces ) {
			$denom = $denom - $bounces;
		}

		$summary['opens_total']   = (int) ( $agg['opens_total'] ?? 0 );
		$summary['opens_unique']  = $opens_unique;
		$summary['clicks_total']  = (int) ( $agg['clicks_total'] ?? 0 );
		$summary['clicks_unique'] = $clicks_unique;
		$summary['bounces_hard']  = $bounces;
		$summary['bounces_soft']  = 0;
		$summary['unsubscribes']  = (int) ( $agg['unsubs'] ?? 0 );
		$summary['abuse_reports'] = (int) ( $agg['spam'] ?? 0 );
		$summary['open_rate']     = $denom > 0 ? min( 1.0, $opens_unique / $denom ) : 0.0;
		$summary['click_rate']    = $denom > 0 ? min( 1.0, $clicks_unique / $denom ) : 0.0;

		return $summary;
	}

	/**
	 * Coverage stored on the report, with volume complete when the channel has sent.
	 *
	 * @param array<string, mixed>|null $report          Normalized report.
	 * @param bool                      $stats_available Channel send gate.
	 */
	private static function coverage_from_report( ?array $report, bool $stats_available ): Coverage {
		$raw      = is_array( $report['coverage'] ?? null ) ? $report['coverage'] : array();
		$coverage = Coverage::from_array( $raw );
		if ( $stats_available && Coverage::VOLUME_NONE === $coverage->volume ) {
			return new Coverage(
				Coverage::VOLUME_COMPLETE,
				$coverage->engagement,
				$coverage->audience,
				$coverage->engagement_since,
				$coverage->tracked_emails_sent
			);
		}
		return $coverage;
	}

	/**
	 * Envelope rates. Null when engagement was not observed.
	 *
	 * @param array<string, mixed>|null $report   Normalized report.
	 * @param Coverage                  $coverage Honesty contract.
	 * @return array{open_rate: float|null, click_rate: float|null}
	 */
	private static function rates_from_report( ?array $report, Coverage $coverage ): array {
		if ( Coverage::ENGAGEMENT_NONE === $coverage->engagement ) {
			return array(
				'open_rate'  => null,
				'click_rate' => null,
			);
		}

		$summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : array();
		return array(
			'open_rate'  => self::nullable_rate( $summary['open_rate'] ?? null ),
			'click_rate' => self::nullable_rate( $summary['click_rate'] ?? null ),
		);
	}

	/**
	 * Cast a stored rate, or null when missing.
	 *
	 * @param mixed $value Stored rate.
	 */
	private static function nullable_rate( mixed $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		return (float) $value;
	}

	/**
	 * Denormalized sort-key rate, or null when untracked.
	 *
	 * @param int    $post_id Email post ID.
	 * @param string $key     Meta key.
	 */
	private static function meta_rate( int $post_id, string $key ): ?float {
		$raw = get_post_meta( $post_id, $key, true );
		if ( '' === $raw || false === $raw || null === $raw ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Convert an ISO-8601 timestamp to GMT MySQL datetime.
	 *
	 * @param string $iso ISO-8601 timestamp.
	 */
	private static function iso_to_mysql( string $iso ): ?string {
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
