<?php
/**
 * Backfill a newsletter list's enforced byline onto its existing campaigns.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Walks a list's campaigns in small, spaced Action Scheduler batches.
 *
 * Why batches: every campaign that changes bumps the site-wide `terms` and
 * `posts` cache versions in core, which makes every cached term query and
 * WP_Query miss at once. One long cron run bumped them thousands of times
 * back to back and starved the object cache on a busy site.
 *
 * Cache-version trade-off, per batch of changed campaigns:
 * - Term counts are deferred, so each touched taxonomy is recounted once.
 * - Core's `posts` version bump on post-meta writes is held back and issued once.
 * - The `terms` version still bumps once per changed campaign for the
 *   relationship write, and once more if `hidden-on-index` is removed. Core
 *   offers no switch for that bump, and `wp_suspend_cache_invalidation()` does
 *   not stop it. Bypassing `wp_set_object_terms()` with raw SQL would also
 *   skip the `set_object_terms` listeners (search index sync), so this class
 *   does not do it. Small batches 30 seconds apart let the caches rewarm
 *   between bursts instead.
 *
 * Only one backfill per list exists at a time. Each list has its own Action
 * Scheduler group, and scheduling a backfill cancels whatever is pending for
 * that list first.
 */
class Newsletter_List_Byline_Backfill {

	/** Action Scheduler group prefix. The list term ID is appended. */
	const GROUP_PREFIX = 'prc-email-builder-list-byline-';

	/** Seconds between the byline change and the first batch. Lets quick re-saves coalesce. */
	const START_DELAY_S = 60;

	/** Seconds between batches. */
	const BATCH_SPACING_S = 30;

	/** Core hooks that bump the `posts` cache version after a post-meta write. */
	const POST_META_VERSION_HOOKS = array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' );

	/**
	 * Action Scheduler group for one list's backfill.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 */
	public static function group( int $list_term_id ): string {
		return self::GROUP_PREFIX . $list_term_id;
	}

	/**
	 * Queue a backfill for a list, replacing any backfill still pending for it.
	 *
	 * A batch that is already running finishes, sees the newer pending backfill
	 * and does not chain a continuation of its own.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 * @return bool Whether a backfill is now scheduled.
	 */
	public static function schedule( int $list_term_id ): bool {
		if (
			! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_get_scheduled_actions' )
			|| ! function_exists( 'as_unschedule_action' )
		) {
			self::log( sprintf( 'not scheduled for list %d: Action Scheduler is unavailable. Run `wp prc email backfill-list-bylines`.', $list_term_id ) );
			return false;
		}

		$group    = self::group( $list_term_id );
		$replaced = 0;
		foreach ( self::get_pending_actions( $list_term_id ) as $action ) {
			as_unschedule_action( Newsletter_List_Byline::APPLY_TO_LIST_HOOK, $action->get_args(), $group );
			++$replaced;
		}

		$action_id = as_schedule_single_action(
			time() + self::START_DELAY_S,
			Newsletter_List_Byline::APPLY_TO_LIST_HOOK,
			array( $list_term_id, 0 ),
			$group
		);

		if ( (int) $action_id <= 0 ) {
			self::log( sprintf( 'could not schedule list %d (replaced %d pending). Run `wp prc email backfill-list-bylines`.', $list_term_id, $replaced ) );
			return false;
		}

		self::log(
			sprintf(
				'scheduled for list %d in %ds (action %d, replaced %d pending).',
				$list_term_id,
				self::START_DELAY_S,
				(int) $action_id,
				$replaced
			)
		);

		return true;
	}

	/**
	 * Action Scheduler callback: run one batch, then queue the next.
	 *
	 * @hook prc_email_builder_apply_list_byline
	 *
	 * @param int                $list_term_id Newsletter list term ID.
	 * @param int                $after_id     Highest campaign ID already handled. 0 starts the list.
	 * @param array<string, int> $totals       Running counts from earlier batches.
	 */
	public static function handle_batch( int $list_term_id, int $after_id = 0, array $totals = array() ): void {
		$totals = array_merge(
			array(
				'batches'   => 0,
				'campaigns' => 0,
				'changed'   => 0,
				'skipped'   => 0,
			),
			array_map( 'intval', $totals )
		);

		$byline_term_id = Newsletter_List_Byline::resolve_term_id( $list_term_id );
		if ( $byline_term_id <= 0 ) {
			self::log( sprintf( 'stopped for list %d: the list has no enforced byline.', $list_term_id ) );
			return;
		}

		if ( 0 === $totals['batches'] ) {
			self::log( sprintf( 'start list %d, byline term %d, batch size %d.', $list_term_id, $byline_term_id, Newsletter_List_Byline::BATCH_SIZE ) );
		}

		$result  = self::run_batch( $list_term_id, $after_id, Newsletter_List_Byline::BATCH_SIZE, false );
		$changed = $result['counts'][ Newsletter_List_Byline::RESULT_ENFORCED ];
		$skipped = $result['processed'] - $changed;

		++$totals['batches'];
		$totals['campaigns'] += $result['processed'];
		$totals['changed']   += $changed;
		$totals['skipped']   += $skipped;

		self::log(
			sprintf(
				'batch %d list %d after campaign %d: %d campaigns, %d changed, %d skipped.',
				$totals['batches'],
				$list_term_id,
				$after_id,
				$result['processed'],
				$changed,
				$skipped
			)
		);

		$stop_reason = '';
		if ( $result['processed'] > 0 && $result['counts'][ Newsletter_List_Byline::RESULT_UNAVAILABLE ] === $result['processed'] ) {
			$stop_reason = ' Stopped: the PRC Staff Bylines plugin is not active.';
		} elseif ( $result['has_more'] && self::has_pending_action( $list_term_id ) ) {
			$stop_reason = ' Stopped: a newer backfill is pending for this list.';
		} elseif ( $result['has_more'] ) {
			$next_id = as_schedule_single_action(
				time() + self::BATCH_SPACING_S,
				Newsletter_List_Byline::APPLY_TO_LIST_HOOK,
				array( $list_term_id, $result['last_id'], $totals ),
				self::group( $list_term_id )
			);
			if ( (int) $next_id > 0 ) {
				return;
			}
			$stop_reason = ' Stopped: the next batch could not be scheduled.';
		}

		self::log(
			sprintf(
				'end list %d: %d batches, %d campaigns, %d changed, %d skipped.%s',
				$list_term_id,
				$totals['batches'],
				$totals['campaigns'],
				$totals['changed'],
				$totals['skipped'],
				$stop_reason
			)
		);
	}

	/**
	 * Attribute one page of a list's campaigns. The cron and WP-CLI paths share this.
	 *
	 * @param int  $list_term_id Newsletter list term ID.
	 * @param int  $after_id     Handle campaigns with an ID above this one.
	 * @param int  $batch_size   Maximum campaigns to handle.
	 * @param bool $dry_run      Report outcomes without writing.
	 * @return array{processed: int, last_id: int, has_more: bool, counts: array<string, int>, changed_ids: int[]}
	 */
	public static function run_batch( int $list_term_id, int $after_id, int $batch_size, bool $dry_run ): array {
		$post_ids = Newsletter_List_Byline::get_list_campaign_ids( $list_term_id, $after_id, $batch_size );
		$counts   = array(
			Newsletter_List_Byline::RESULT_ENFORCED    => 0,
			Newsletter_List_Byline::RESULT_CURRENT     => 0,
			Newsletter_List_Byline::RESULT_NO_LIST     => 0,
			Newsletter_List_Byline::RESULT_NO_BYLINE   => 0,
			Newsletter_List_Byline::RESULT_UNAVAILABLE => 0,
		);
		$changed  = array();

		$scope = $dry_run ? null : self::begin_write_scope();
		try {
			foreach ( $post_ids as $post_id ) {
				$result = Newsletter_List_Byline::apply( $post_id, $dry_run );
				++$counts[ $result ];
				if ( Newsletter_List_Byline::RESULT_ENFORCED === $result ) {
					$changed[] = $post_id;
				}
			}
		} finally {
			if ( null !== $scope ) {
				self::end_write_scope( $scope, count( $changed ) );
			}
		}

		return array(
			'processed'   => count( $post_ids ),
			'last_id'     => array() === $post_ids ? $after_id : max( $post_ids ),
			'has_more'    => count( $post_ids ) === $batch_size,
			'counts'      => $counts,
			'changed_ids' => $changed,
		);
	}

	/**
	 * Defer term counting and hold back core's per-meta-write `posts` version bump.
	 *
	 * @return array{counting_was_deferred: bool, hooks: array<string, int>}
	 */
	private static function begin_write_scope(): array {
		$counting_was_deferred = (bool) wp_defer_term_counting();
		wp_defer_term_counting( true );

		$hooks = array();
		foreach ( self::POST_META_VERSION_HOOKS as $hook ) {
			$priority = has_action( $hook, 'wp_cache_set_posts_last_changed' );
			if ( false !== $priority ) {
				remove_action( $hook, 'wp_cache_set_posts_last_changed', $priority );
				$hooks[ $hook ] = $priority;
			}
		}

		return array(
			'counting_was_deferred' => $counting_was_deferred,
			'hooks'                 => $hooks,
		);
	}

	/**
	 * Undo begin_write_scope(): recount terms once and bump the `posts` version once.
	 *
	 * @param array{counting_was_deferred: bool, hooks: array<string, int>} $scope        State from begin_write_scope().
	 * @param int                                                           $changed_count Campaigns written in the scope.
	 */
	private static function end_write_scope( array $scope, int $changed_count ): void {
		foreach ( $scope['hooks'] as $hook => $priority ) {
			add_action( $hook, 'wp_cache_set_posts_last_changed', $priority );
		}

		if ( ! $scope['counting_was_deferred'] ) {
			wp_defer_term_counting( false );
		}

		if ( $changed_count > 0 ) {
			wp_cache_set_posts_last_changed();
		}
	}

	/**
	 * Whether another batch is waiting for this list. A running batch does not count.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 */
	private static function has_pending_action( int $list_term_id ): bool {
		return array() !== self::get_pending_actions( $list_term_id, 1 );
	}

	/**
	 * Pending Action Scheduler actions for a list.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 * @param int $limit        Maximum actions. -1 for all.
	 * @return object[] Actions exposing get_args().
	 */
	private static function get_pending_actions( int $list_term_id, int $limit = -1 ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		return array_values(
			as_get_scheduled_actions(
				array(
					'hook'     => Newsletter_List_Byline::APPLY_TO_LIST_HOOK,
					'group'    => self::group( $list_term_id ),
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => $limit,
				)
			)
		);
	}

	/**
	 * Write a line to the PHP error log, which VIP ships to the application logs.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator-visible backfill progress.
		error_log( '[prc-email-builder] list byline backfill: ' . $message );
	}
}
