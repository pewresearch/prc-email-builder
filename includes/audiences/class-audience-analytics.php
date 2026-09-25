<?php
/**
 * Per-audience engagement rollups for the Audiences list.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use PRC\Platform\Email_Builder\Reports\Report_Store;

/**
 * Aggregates stored report meta per newsletter list and per recipient list.
 *
 * "Sent" counts emails whose provider status says they went out. Averages use
 * only posts with a stored rate, so unsynced reports do not pull them toward 0.
 */
final class Audience_Analytics {
	private const CAMPAIGN_SENT_STATUS        = 'sent';
	private const TRANSACTIONAL_SENT_STATUSES = array( 'sent', 'queued' );

	/**
	 * Analytics block for a row with no sends.
	 *
	 * @return array{sentCount: int, avgOpenRate: null, avgClickRate: null, lastSentAt: null}
	 */
	public static function empty_block(): array {
		return array(
			'sentCount'    => 0,
			'avgOpenRate'  => null,
			'avgClickRate' => null,
			'lastSentAt'   => null,
		);
	}

	/**
	 * Rollups for newsletter lists, keyed by term ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_newsletter_lists(): array {
		global $wpdb;

		$sql  = $wpdb->prepare(
			"SELECT tt.term_id AS group_key,
				p.ID AS post_id,
				open_rate.meta_value AS open_rate,
				click_rate.meta_value AS click_rate,
				COALESCE(NULLIF(send_time.meta_value, ''), p.post_date_gmt) AS sent_at
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
			INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = %s AND status.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} open_rate ON open_rate.post_id = p.ID AND open_rate.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} click_rate ON click_rate.post_id = p.ID AND click_rate.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} send_time ON send_time.post_id = p.ID AND send_time.meta_key = %s
			WHERE p.post_type = %s",
			Post_Type::TAXONOMY,
			'prc_email_mailchimp_campaign_status',
			self::CAMPAIGN_SENT_STATUS,
			Report_Store::META_OPEN_RATE,
			Report_Store::META_CLICK_RATE,
			Report_Store::META_SEND_TIME,
			Post_Type::CAMPAIGN_POST_TYPE
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; one query for every list.

		$blocks = array();
		foreach ( self::shape_rows( self::group_posts( is_array( $rows ) ? $rows : array() ) ) as $key => $block ) {
			$blocks[ (int) $key ] = $block;
		}

		return $blocks;
	}

	/**
	 * Rollups for stored recipient lists, keyed by audience option key.
	 *
	 * Last sent uses the report send time, then the Mandrill summary `sent_at`.
	 * It does not use the post date or the post modified time.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_recipient_lists(): array {
		global $wpdb;

		$statuses     = self::TRANSACTIONAL_SENT_STATUSES;
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated %s placeholders are interpolated.
		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The placeholder list is generated from the status count.
			"SELECT audience.meta_value AS group_key,
				p.ID AS post_id,
				open_rate.meta_value AS open_rate,
				click_rate.meta_value AS click_rate,
				send_time.meta_value AS report_send_time,
				summary.meta_value AS send_summary
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} audience ON audience.post_id = p.ID AND audience.meta_key = %s
			INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = %s AND status.meta_value IN ({$placeholders})
			LEFT JOIN {$wpdb->postmeta} open_rate ON open_rate.post_id = p.ID AND open_rate.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} click_rate ON click_rate.post_id = p.ID AND click_rate.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} send_time ON send_time.post_id = p.ID AND send_time.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} summary ON summary.post_id = p.ID AND summary.meta_key = %s
			WHERE p.post_type = %s",
			'prc_email_audience_option_key',
			'prc_email_mandrill_send_status',
			...array_merge(
				$statuses,
				array(
					Report_Store::META_OPEN_RATE,
					Report_Store::META_CLICK_RATE,
					Report_Store::META_SEND_TIME,
					'prc_email_mandrill_send_summary',
					Post_Type::TRANSACTIONAL_POST_TYPE,
				)
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; one query for every recipient list.

		$posts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['sent_at'] = self::recipient_sent_at( $row );
			$posts[]        = $row;
		}

		return self::shape_rows( self::group_posts( $posts ) );
	}

	/**
	 * Send time for one recipient-list email.
	 *
	 * The report send time wins once a sync has stored it. Before that, use
	 * `sent_at` from the Mandrill send summary. That value is written at send
	 * time and does not move when the email is edited. Post dates are not a
	 * fallback: a draft's post date is the create time, and post modified
	 * changes on later edits.
	 *
	 * @param array<string, mixed> $row Query row.
	 */
	private static function recipient_sent_at( array $row ): ?string {
		$candidates = array(
			$row['report_send_time'] ?? null,
			self::summary_sent_at( $row['send_summary'] ?? null ),
		);
		foreach ( $candidates as $candidate ) {
			if ( null !== self::timestamp( $candidate ) ) {
				return is_string( $candidate ) ? $candidate : null;
			}
		}

		return null;
	}

	/**
	 * `sent_at` from a Mandrill send-summary JSON blob, or null.
	 *
	 * @param mixed $summary Raw `prc_email_mandrill_send_summary` meta.
	 */
	private static function summary_sent_at( mixed $summary ): ?string {
		if ( ! is_string( $summary ) || '' === $summary ) {
			return null;
		}
		$decoded = json_decode( $summary, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$sent_at = $decoded['sent_at'] ?? null;

		return is_string( $sent_at ) && '' !== $sent_at ? $sent_at : null;
	}

	/**
	 * Group per-post rows into rollup rows.
	 *
	 * Send times mix ISO 8601 and MySQL GMT strings, so the latest one is picked
	 * by timestamp here rather than by string order in SQL.
	 *
	 * @param array<int, array<string, mixed>> $rows Per-post rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function group_posts( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$key     = (string) ( $row['group_key'] ?? '' );
			$post_id = (int) ( $row['post_id'] ?? 0 );
			if ( '' === $key || isset( $groups[ $key ]['posts'][ $post_id ] ) ) {
				continue;
			}
			$group                      = $groups[ $key ] ?? array(
				'posts'       => array(),
				'open_rates'  => array(),
				'click_rates' => array(),
				'last_sent'   => null,
			);
			$group['posts'][ $post_id ] = true;
			$buckets                    = array(
				'open_rate'  => 'open_rates',
				'click_rate' => 'click_rates',
			);
			foreach ( $buckets as $field => $bucket ) {
				if ( isset( $row[ $field ] ) && is_numeric( $row[ $field ] ) ) {
					$group[ $bucket ][] = (float) $row[ $field ];
				}
			}
			$sent_at = self::timestamp( $row['sent_at'] ?? null );
			if ( null !== $sent_at && ( null === $group['last_sent'] || $sent_at > $group['last_sent'] ) ) {
				$group['last_sent'] = $sent_at;
			}
			$groups[ $key ] = $group;
		}

		$grouped = array();
		foreach ( $groups as $key => $group ) {
			$grouped[] = array(
				'group_key'      => (string) $key,
				'sent_count'     => count( $group['posts'] ),
				'avg_open_rate'  => $group['open_rates'] ? array_sum( $group['open_rates'] ) / count( $group['open_rates'] ) : null,
				'avg_click_rate' => $group['click_rates'] ? array_sum( $group['click_rates'] ) / count( $group['click_rates'] ) : null,
				'last_sent_at'   => null === $group['last_sent'] ? null : gmdate( 'Y-m-d\TH:i:s\Z', $group['last_sent'] ),
			);
		}

		return $grouped;
	}

	/**
	 * Convert grouped SQL rows to analytics blocks keyed by group key.
	 *
	 * @param array<int, array<string, mixed>> $rows Grouped rows.
	 * @return array<string|int, array<string, mixed>>
	 */
	public static function shape_rows( array $rows ): array {
		$blocks = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row['group_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$blocks[ $key ] = array(
				'sentCount'    => max( 0, (int) ( $row['sent_count'] ?? 0 ) ),
				'avgOpenRate'  => self::rate( $row['avg_open_rate'] ?? null ),
				'avgClickRate' => self::rate( $row['avg_click_rate'] ?? null ),
				'lastSentAt'   => self::iso_time( $row['last_sent_at'] ?? null ),
			);
		}

		return $blocks;
	}

	/**
	 * Rate between 0 and 1, rounded to four places, or null.
	 *
	 * @param mixed $value SQL average.
	 */
	private static function rate( mixed $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return round( max( 0.0, min( 1.0, (float) $value ) ), 4 );
	}

	/**
	 * ISO 8601 UTC for a stored send time (ISO or MySQL GMT), or null.
	 *
	 * @param mixed $value Stored time.
	 */
	private static function iso_time( mixed $value ): ?string {
		$timestamp = self::timestamp( $value );

		return null === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Unix timestamp for a stored send time (ISO or MySQL GMT), or null.
	 *
	 * @param mixed $value Stored time.
	 */
	private static function timestamp( mixed $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$timestamp = strtotime( str_contains( $value, 'T' ) ? $value : $value . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}
}
