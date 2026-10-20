<?php
/**
 * WP-CLI command: attribute existing campaigns to their newsletter list's enforced byline.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * Backport the list's enforced byline onto campaigns saved before it was set.
 */
class CLI_Backfill_List_Bylines extends \WPCOM_VIP_CLI_Command {

	private const BATCH_SIZE    = 100;
	private const BATCH_SLEEP_S = 2;

	/**
	 * Attribute a newsletter list's existing campaigns to the list's enforced byline.
	 *
	 * Runs the same enforcement a campaign save runs: adds the staff byline as an
	 * acknowledgement and clears hidden-on-index. Campaigns that are already
	 * attributed are skipped, so the command is safe to re-run.
	 *
	 * ## OPTIONS
	 *
	 * --list=<slug|id>
	 * : Newsletter list term slug or ID. The list must have a staff byline set.
	 *
	 * [--dry-run]
	 * : Preview changes without writing. Default: true. Pass `--dry-run=false` to execute.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email backfill-list-bylines --list=quarterly-update
	 *
	 *     wp prc email backfill-list-bylines --list=quarterly-update --dry-run=false
	 *
	 * @when after_wp_load
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = true;
		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = 'false' === $assoc_args['dry-run'] ? false : (bool) $assoc_args['dry-run'];
		}

		$list_term_id = $this->resolve_list_term_id( (string) ( $assoc_args['list'] ?? '' ) );
		if ( $list_term_id <= 0 ) {
			WP_CLI::error( 'No newsletter list matches --list. Pass a list slug or term ID.' );
		}

		if ( Newsletter_List_Byline::resolve_term_id( $list_term_id ) <= 0 ) {
			WP_CLI::error( 'This list has no staff byline. Set one on the list, then run this command again.' );
		}

		if ( $dry_run ) {
			WP_CLI::log( 'DRY RUN — no campaigns will be modified. Use --dry-run=false to execute.' );
		}

		$counts = array(
			Newsletter_List_Byline::RESULT_ENFORCED    => 0,
			Newsletter_List_Byline::RESULT_CURRENT     => 0,
			Newsletter_List_Byline::RESULT_NO_LIST     => 0,
			Newsletter_List_Byline::RESULT_NO_BYLINE   => 0,
			Newsletter_List_Byline::RESULT_UNAVAILABLE => 0,
		);

		$this->start_bulk_operation();

		$page    = 1;
		$is_full = false;
		do {
			$post_ids = $this->get_campaign_ids( $list_term_id, $page );
			$is_full  = count( $post_ids ) === self::BATCH_SIZE;

			foreach ( $post_ids as $post_id ) {
				$result = Newsletter_List_Byline::apply( (int) $post_id, $dry_run );
				++$counts[ $result ];
				if ( Newsletter_List_Byline::RESULT_ENFORCED === $result ) {
					WP_CLI::log( sprintf( '%s campaign %d.', $dry_run ? 'Would update' : 'Updated', $post_id ) );
				}
			}

			$this->vip_inmemory_cleanup();
			if ( $is_full ) {
				sleep( self::BATCH_SLEEP_S );
			}
			++$page;
		} while ( $is_full );

		$this->end_bulk_operation();

		if ( $counts[ Newsletter_List_Byline::RESULT_UNAVAILABLE ] > 0 ) {
			WP_CLI::warning( 'The PRC Staff Bylines plugin is not active, so no campaigns were changed.' );
		}

		WP_CLI::success(
			sprintf(
				'Done. %d campaigns checked, %d %s, %d already current.',
				array_sum( $counts ),
				$counts[ Newsletter_List_Byline::RESULT_ENFORCED ],
				$dry_run ? 'would be updated' : 'updated',
				$counts[ Newsletter_List_Byline::RESULT_CURRENT ]
			)
		);
	}

	/**
	 * Resolve a list slug or term ID to a newsletter list term ID.
	 *
	 * @param string $slug_or_id Slug or numeric term ID.
	 */
	private function resolve_list_term_id( string $slug_or_id ): int {
		if ( '' === $slug_or_id ) {
			return 0;
		}

		$term = ctype_digit( $slug_or_id )
			? get_term( (int) $slug_or_id, Post_Type::TAXONOMY )
			: get_term_by( 'slug', $slug_or_id, Post_Type::TAXONOMY );

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * One page of campaign IDs on the list. Enforcement never changes list
	 * membership, so page numbers stay stable across batches.
	 *
	 * @param int $list_term_id Newsletter list term ID.
	 * @param int $page         1-based page.
	 * @return int[]
	 */
	private function get_campaign_ids( int $list_term_id, int $page ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Type::CAMPAIGN_POST_TYPE,
				'post_status'            => array( 'publish', 'future', 'pending', 'draft', 'private' ),
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => Post_Type::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => array( $list_term_id ),
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
