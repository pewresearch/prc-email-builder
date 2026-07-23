<?php
declare(strict_types=1);
/**
 * Scheduled and on-demand Mailchimp engagement report sync.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder\Reports;

use PRC\Platform\Email_Builder\Migration;
use PRC\Platform\Email_Builder\Option_Lock;
use PRC\Platform\Email_Builder\Post_Type;
use WP_Error;

/**
 * Recurring Action Scheduler job + per-campaign fetch workers.
 */
class Report_Sync {

	const SYNC_HOOK    = 'prc_email_report_sync';
	const FETCH_HOOK   = 'prc_email_report_fetch';
	const ACTION_GROUP = 'prc-email-builder';
	const INTERVAL     = DAY_IN_SECONDS;

	const LOCK_OPTION_PREFIX = 'prc_email_report_lock_';
	const LOCK_TTL           = 120;

	/**
	 * Register hooks and ensure the recurring job is scheduled.
	 */
	public static function init(): void {
		add_action( self::SYNC_HOOK, [ __CLASS__, 'sync' ] );
		add_action( self::FETCH_HOOK, [ __CLASS__, 'fetch_one' ], 10, 1 );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'updated_post_meta', [ __CLASS__, 'maybe_enqueue_on_sent' ], 10, 4 );
	}

	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::SYNC_HOOK, [], self::ACTION_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL,
			self::INTERVAL,
			self::SYNC_HOOK,
			[],
			self::ACTION_GROUP
		);
	}

	/**
	 * Enumerate eligible sent campaigns and enqueue per-post fetches.
	 */
	public static function sync(): void {
		$page = 1;

		do {
			$query = new \WP_Query(
				[
					'post_type'      => Post_Type::CAMPAIGN_POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'meta_query'     => self::eligibility_meta_query( true ),
				]
			);

			foreach ( $query->posts as $post_id ) {
				$post_id = (int) $post_id;
				if ( ! self::is_within_refresh_window( $post_id ) ) {
					continue;
				}
				self::enqueue_fetch( $post_id );
			}

			if ( function_exists( 'vip_inmemory_cleanup' ) ) {
				vip_inmemory_cleanup();
			}

			$page++;
		} while ( $page <= (int) $query->max_num_pages );
	}

	/**
	 * Action Scheduler worker: fetch one campaign report.
	 *
	 * @param int|string $post_id Campaign post ID.
	 */
	public static function fetch_one( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		self::pull_and_store( $post_id, false );
		sleep( 1 );
	}

	/**
	 * On-demand refresh (ignores refresh window).
	 *
	 * @return array<string, mixed>|WP_Error Report envelope on success.
	 */
	public static function refresh_now( int $post_id ): array|WP_Error {
		if ( ! self::is_eligible_post( $post_id, true ) ) {
			return new WP_Error(
				'report_refresh_not_eligible',
				'This campaign is not eligible for report refresh.',
				[ 'status' => 400 ]
			);
		}

		$last = Report_Store::get_last_synced( $post_id );
		if ( '' !== $last ) {
			$last_ts = strtotime( $last );
			if ( $last_ts && ( time() - $last_ts ) < Report_Schema::refresh_min_interval() ) {
				return new WP_Error(
					'report_refresh_throttled',
					'Report was refreshed recently. Please wait before refreshing again.',
					[ 'status' => 429 ]
				);
			}
		}

		$result = self::pull_and_store( $post_id, true );
		if ( is_wp_error( $result ) ) {
			if ( Report_Store::STATE_UNAVAILABLE === Report_Store::get_sync_state( $post_id ) ) {
				return Report_Store::envelope( $post_id );
			}
			return $result;
		}

		return Report_Store::envelope( $post_id );
	}

	/**
	 * @return true|WP_Error True on success.
	 */
	private static function pull_and_store( int $post_id, bool $force ): true|WP_Error {
		if ( ! self::is_eligible_post( $post_id, $force ) ) {
			return new WP_Error( 'report_not_eligible', 'Campaign is not eligible for report sync.' );
		}

		$lock_token = self::lock()->acquire( $post_id );
		if ( '' === $lock_token ) {
			return new WP_Error(
				'report_sync_in_progress',
				'A report sync is already in progress for this campaign.',
				[ 'status' => 409 ]
			);
		}

		try {
			$campaign_id = (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true );
			if ( '' === $campaign_id ) {
				return new WP_Error( 'report_missing_campaign_id', 'No Mailchimp campaign ID on this post.' );
			}

			$provider = new Mailchimp_Report_Provider();
			$report   = $provider->fetch( $campaign_id );

		if ( is_wp_error( $report ) ) {
				$data   = $report->get_error_data();
				$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
				if ( 404 === $status ) {
					Report_Store::mark_unavailable( $post_id );
				} else {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[prc-email-builder] Report sync failed for post %d / campaign %s: %s',
							$post_id,
							$campaign_id,
							$report->get_error_message()
						)
					);
					if ( 429 === $status && function_exists( 'as_schedule_single_action' ) ) {
						as_schedule_single_action(
							time() + 60,
							self::FETCH_HOOK,
							[ $post_id ],
							self::ACTION_GROUP
						);
					}
				}
				return $report;
			}

			Report_Store::save_report( $post_id, $report );
			return true;
		} finally {
			self::lock()->release( $post_id, $lock_token );
		}
	}

	/**
	 * When status transitions to sent, enqueue an immediate fetch.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function maybe_enqueue_on_sent( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( 'prc_email_mailchimp_campaign_status' !== $meta_key || 'sent' !== (string) $meta_value ) {
			return;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! Post_Type::is_campaign_post( $post_id ) ) {
			return;
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return;
		}

		self::enqueue_fetch( $post_id );
	}

	private static function enqueue_fetch( int $post_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::fetch_one( $post_id );
			return;
		}

		as_enqueue_async_action(
			self::FETCH_HOOK,
			[ $post_id ],
			self::ACTION_GROUP,
			true
		);
	}

	/**
	 * @param bool $for_scheduled When true, exclude unavailable terminal state.
	 * @return array<int|string, mixed>
	 */
	private static function eligibility_meta_query( bool $for_scheduled ): array {
		$clauses = [
			'relation' => 'AND',
			[
				'key'     => 'prc_email_mailchimp_campaign_id',
				'compare' => '!=',
				'value'   => '',
			],
			[
				'key'     => 'prc_email_mailchimp_campaign_status',
				'value'   => 'sent',
				'compare' => '=',
			],
			[
				'key'     => Migration::MIGRATED_META_KEY,
				'compare' => 'NOT EXISTS',
			],
		];

		if ( $for_scheduled ) {
			$clauses[] = [
				'relation' => 'OR',
				[
					'key'     => Report_Store::META_SYNC_STATE,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => Report_Store::META_SYNC_STATE,
					'value'   => Report_Store::STATE_UNAVAILABLE,
					'compare' => '!=',
				],
			];
		}

		return $clauses;
	}

	private static function is_eligible_post( int $post_id, bool $ignore_window ): bool {
		if ( ! Post_Type::is_campaign_post( $post_id ) ) {
			return false;
		}
		if ( Migration::is_migrated( $post_id ) ) {
			return false;
		}
		if ( 'sent' !== (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_status', true ) ) {
			return false;
		}
		if ( '' === (string) get_post_meta( $post_id, 'prc_email_mailchimp_campaign_id', true ) ) {
			return false;
		}
		if ( Report_Store::STATE_UNAVAILABLE === Report_Store::get_sync_state( $post_id ) ) {
			return false;
		}
		if ( ! $ignore_window && ! self::is_within_refresh_window( $post_id ) ) {
			return false;
		}
		return true;
	}

	private static function is_within_refresh_window( int $post_id ): bool {
		$send_time = (string) get_post_meta( $post_id, Report_Store::META_SEND_TIME, true );
		if ( '' === $send_time ) {
			return true;
		}

		$anchor = strtotime( $send_time );
		if ( ! $anchor ) {
			return true;
		}

		$window_seconds = Report_Schema::refresh_window_days() * DAY_IN_SECONDS;
		return ( time() - $anchor ) <= $window_seconds;
	}

	/**
	 * Per-campaign report sync lock (separate prefix/TTL from Mandrill sends).
	 */
	private static function lock(): Option_Lock {
		return new Option_Lock( self::LOCK_OPTION_PREFIX, self::LOCK_TTL, 'report_' );
	}
}
