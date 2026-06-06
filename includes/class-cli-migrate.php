<?php
declare(strict_types=1);
/**
 * WP-CLI command for Newsletter Glue migration.
 *
 * Fallback / debugging tool that calls the same Migration::migrate_single_post()
 * used by the Action Scheduler jobs.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

class CLI_Migrate {

	/**
	 * Migrate Newsletter Glue posts to PRC Newsletter Builder.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview changes without writing. Default: true. Pass `--dry-run=false` to execute.
	 *
	 * [--limit=<number>]
	 * : Process only N posts.
	 *
	 * [--post-id=<id>]
	 * : Migrate a single NGL post by ID.
	 *
	 * [--verbose]
	 * : Show detailed per-post transform output.
	 *
	 * [--force]
	 * : Re-migrate posts that have already been migrated.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry-run all posts
	 *     wp prc email migrate
	 *
	 *     # Actually migrate all posts
	 *     wp prc email migrate --dry-run=false
	 *
	 *     # Migrate a single post with verbose output
	 *     wp prc email migrate --post-id=12345 --dry-run=false --verbose
	 *
	 *     # Re-migrate a previously migrated post
	 *     wp prc email migrate --post-id=12345 --dry-run=false --force
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = 'false' === $assoc_args['dry-run'] ? false : (bool) $assoc_args['dry-run'];
		} else {
			$dry_run = true;
		}

		$limit   = (int) Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$post_id = (int) Utils\get_flag_value( $assoc_args, 'post-id', 0 );
		$verbose = Utils\get_flag_value( $assoc_args, 'verbose', false );
		$force   = Utils\get_flag_value( $assoc_args, 'force', false );

		if ( $dry_run ) {
			WP_CLI::log( 'DRY RUN — no posts will be created or modified. Use --dry-run=false to execute.' );
		}

		$post_ids = $this->get_post_ids( $post_id, $limit );

		if ( empty( $post_ids ) ) {
			WP_CLI::warning( 'No newsletterglue posts found to migrate.' );
			return;
		}

		$total    = count( $post_ids );
		$progress = Utils\make_progress_bar( "Migrating {$total} NGL posts", $total );
		$results  = [];

		$migration = new Migration();

		foreach ( $post_ids as $ngl_id ) {
			$ngl_post = get_post( $ngl_id );
			if ( ! $ngl_post ) {
				$results[] = $this->result_row( $ngl_id, null, 'error', 'Post not found' );
				$progress->tick();
				continue;
			}

			if ( $dry_run ) {
				$migration->reset_warnings_for_run();
				$transformed = $migration->transform_blocks( $ngl_post->post_content );
				$warnings    = $migration->get_warnings();
				$warn_text   = ! empty( $warnings ) ? implode( '; ', $warnings ) : '';

				if ( $verbose ) {
					WP_CLI::log( "\n--- NGL Post {$ngl_id}: {$ngl_post->post_title} ---" );
					WP_CLI::log( "Original block count: " . count( parse_blocks( $ngl_post->post_content ) ) );
					WP_CLI::log( "Transformed block count: " . count( parse_blocks( $transformed ) ) );
					if ( $warn_text ) {
						WP_CLI::log( "Warnings: {$warn_text}" );
					}
				}

				$results[] = $this->result_row( $ngl_id, null, 'dry-run', $warn_text );
				$progress->tick();
				continue;
			}

			$result = $migration->migrate_single_post( $ngl_id, $force );

			if ( is_wp_error( $result ) ) {
				$results[] = $this->result_row( $ngl_id, null, $result->get_error_code(), $result->get_error_message() );
			} else {
				$warnings  = $migration->get_warnings();
				$warn_text = ! empty( $warnings ) ? implode( '; ', $warnings ) : '';
				$results[] = $this->result_row( $ngl_id, $result, 'migrated', $warn_text );

				if ( $verbose ) {
					WP_CLI::log( "  NGL {$ngl_id} -> email post {$result}" );
				}
			}

			$progress->tick();

			if ( function_exists( 'vip_inmemory_cleanup' ) ) {
				vip_inmemory_cleanup();
			}
		}

		$progress->finish();

		WP_CLI\Utils\format_items(
			'table',
			$results,
			[ 'ngl_id', 'new_id', 'status', 'notes' ]
		);

		$migrated = array_filter( $results, fn( $r ) => 'migrated' === $r['status'] );
		$errors   = array_filter( $results, fn( $r ) => ! in_array( $r['status'], [ 'migrated', 'dry-run', 'already_migrated' ], true ) );

		WP_CLI::success( sprintf(
			'Done. %d processed, %d migrated, %d errors.',
			$total,
			count( $migrated ),
			count( $errors )
		) );
	}

	/**
	 * Get the list of NGL post IDs to process.
	 */
	private function get_post_ids( int $single_id, int $limit ): array {
		if ( $single_id > 0 ) {
			return [ $single_id ];
		}

		$args = [
			'post_type'      => Migration::NGL_POST_TYPE,
			'post_status'    => Migration::NGL_MIGRATION_POST_STATUSES,
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'ASC',
		];

		$query = new \WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Build a result row for the summary table.
	 */
	private function result_row( int $ngl_id, ?int $new_id, string $status, string $notes ): array {
		return [
			'ngl_id' => $ngl_id,
			'new_id' => $new_id ?? '—',
			'status' => $status,
			'notes'  => $notes,
		];
	}
}
