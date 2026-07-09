<?php
declare(strict_types=1);
/**
 * Action Scheduler dispatcher for Newsletter Glue migration.
 *
 * Two-phase flow:
 *  Phase 1 (DISPATCH_HOOK): queries `newsletterglue` posts (publish/draft/future)
 *           and schedules one PROCESS_HOOK job per post.
 *  Phase 2 (PROCESS_HOOK): migrates a single NGL post via Migration::migrate_single_post().
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

class Migration_Scheduler {

	const DISPATCH_HOOK = 'prc_email_migration_dispatch';
	const PROCESS_HOOK  = 'prc_email_migration_process';
	const ACTION_GROUP  = 'prc-email-migration';
	const FAILED_HOOK   = 'prc_email_migration_failed';

	/**
	 * Register Action Scheduler hooks. Call once during plugin bootstrap.
	 */
	public static function init(): void {
		add_action( self::DISPATCH_HOOK, [ __CLASS__, 'dispatch' ] );
		add_action( self::PROCESS_HOOK, [ __CLASS__, 'process' ], 10, 1 );
	}

	/**
	 * Schedule the Phase 1 dispatcher job.
	 * Guarded against duplicate scheduling.
	 */
	public static function schedule_dispatch(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-email-builder: Action Scheduler not available. Migration dispatch skipped. Use WP-CLI fallback.' );
			return;
		}

		if ( self::is_dispatch_pending() ) {
			return;
		}

		as_schedule_single_action(
			time(),
			self::DISPATCH_HOOK,
			[],
			self::ACTION_GROUP
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'prc-email-builder: Migration dispatch job scheduled.' );
	}

	/**
	 * Phase 1: query all NGL posts and fan out per-post jobs.
	 */
	public static function dispatch(): void {
		$query = new \WP_Query( [
			'post_type'      => Migration::NGL_POST_TYPE,
			'post_status'    => Migration::NGL_MIGRATION_POST_STATUSES,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		$post_ids = $query->posts;

		if ( empty( $post_ids ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-email-builder: No newsletterglue posts found to migrate.' );
			return;
		}

		$count = count( $post_ids );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "prc-email-builder: Dispatching migration for {$count} NGL posts." );

		$stagger_seconds = 2;

		foreach ( $post_ids as $index => $post_id ) {
			as_schedule_single_action(
				time() + ( $index * $stagger_seconds ),
				self::PROCESS_HOOK,
				[ 'ngl_post_id' => (int) $post_id ],
				self::ACTION_GROUP
			);
		}
	}

	/**
	 * Phase 2: migrate a single NGL post.
	 *
	 * @param int $ngl_post_id The source newsletterglue post ID.
	 */
	public static function process( int $ngl_post_id ): void {
		$migration = new Migration();
		$result    = $migration->migrate_single_post( $ngl_post_id );

		if ( is_wp_error( $result ) ) {
			$code    = $result->get_error_code();
			$message = $result->get_error_message();

			if ( 'already_migrated' === $code ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "prc-email-builder: Skipping NGL post {$ngl_post_id} — {$message}" );
				return;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "prc-email-builder: Migration failed for NGL post {$ngl_post_id} — [{$code}] {$message}" );

			/**
			 * Fires when a newsletter migration job fails.
			 *
			 * @param int      $ngl_post_id NGL post ID.
			 * @param WP_Error $error       Error details.
			 */
			do_action( self::FAILED_HOOK, $ngl_post_id, $result );
			return;
		}

		$warnings = $migration->get_warnings();
		$warn_str = ! empty( $warnings ) ? ' Warnings: ' . implode( '; ', $warnings ) : '';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "prc-email-builder: Migrated NGL post {$ngl_post_id} -> email post {$result}.{$warn_str}" );
	}

	/**
	 * Check if a dispatch job is already pending or running.
	 */
	private static function is_dispatch_pending(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => self::DISPATCH_HOOK,
				'status'   => [
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING,
				],
				'group'    => self::ACTION_GROUP,
				'per_page' => 1,
			],
			'ids'
		);

		return ! empty( $actions );
	}
}
