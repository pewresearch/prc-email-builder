<?php
/**
 * WP-CLI command: migrate prc_email_system_email_key meta to post slugs.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Align transactional email post slugs with legacy system email keys, then drop the meta.
 */
class CLI_System_Key_Migrate {

	private const LEGACY_META_KEY = 'prc_email_system_email_key';

	/**
	 * Migrate legacy system email keys to post slugs.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview changes without writing. Default: true. Pass `--dry-run=false` to execute.
	 *
	 * [--post-id=<id>]
	 * : Migrate a single transactional email post by ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc email migrate-system-keys
	 *
	 *     wp prc email migrate-system-keys --dry-run=false
	 *
	 *     wp prc email migrate-system-keys --post-id=12345 --dry-run=false
	 *
	 * @when after_wp_load
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = 'false' === $assoc_args['dry-run'] ? false : (bool) $assoc_args['dry-run'];
		} else {
			$dry_run = true;
		}

		$post_id = (int) Utils\get_flag_value( $assoc_args, 'post-id', 0 );

		if ( $dry_run ) {
			WP_CLI::log( 'DRY RUN — no posts will be modified. Use --dry-run=false to execute.' );
		}

		$rows    = $this->fetch_legacy_rows( $post_id );
		$results = array();

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No transactional emails with a legacy system email key were found.' );
			return;
		}

		foreach ( $rows as $row ) {
			$results[] = $this->migrate_row( $row, $dry_run );
		}

		WP_CLI\Utils\format_items(
			'table',
			$results,
			array( 'post_id', 'old_slug', 'target_slug', 'result_slug', 'status', 'notes' )
		);

		$migrated = array_filter( $results, static fn( array $row ): bool => 'migrated' === $row['status'] );
		$skipped  = array_filter( $results, static fn( array $row ): bool => 'skipped' === $row['status'] );
		$warnings = array_filter( $results, static fn( array $row ): bool => 'warning' === $row['status'] );
		$errors   = array_filter( $results, static fn( array $row ): bool => 'error' === $row['status'] );

		WP_CLI::success(
			sprintf(
				'Done. %d processed, %d migrated, %d skipped, %d warnings, %d errors.',
				count( $results ),
				count( $migrated ),
				count( $skipped ),
				count( $warnings ),
				count( $errors )
			)
		);
	}

	/**
	 * Fetch legacy rows.
	 *
	 * @param int $post_id Post id.
	 * @return array<int, object{ID: string, post_name: string, system_key: string}>
	 */
	private function fetch_legacy_rows( int $post_id ): array {
		global $wpdb;

		$sql = "
			SELECT p.ID, p.post_name, pm.meta_value AS system_key
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND pm.meta_key = %s
			AND pm.meta_value != ''
		";

		$prepare = array( Post_Type::TRANSACTIONAL_POST_TYPE, self::LEGACY_META_KEY );

		if ( $post_id > 0 ) {
			$sql      .= ' AND p.ID = %d';
			$prepare[] = $post_id;
		}

		$sql .= ' ORDER BY p.ID ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare ) );
	}

	/**
	 * Migrate row.
	 *
	 * @param object $row     Row from the migrate query (ID, post_name, system_key).
	 * @param bool   $dry_run Dry run.
	 * @return array{post_id: int, old_slug: string, target_slug: string, result_slug: string, status: string, notes: string}
	 */
	private function migrate_row( object $row, bool $dry_run ): array {
		$id          = (int) $row->ID;
		$old_slug    = (string) $row->post_name;
		$target_slug = sanitize_title( (string) $row->system_key );

		$result = array(
			'post_id'     => $id,
			'old_slug'    => $old_slug,
			'target_slug' => $target_slug,
			'result_slug' => $old_slug,
			'status'      => 'skipped',
			'notes'       => '',
		);

		if ( '' === $target_slug ) {
			$result['status'] = 'error';
			$result['notes']  = 'Legacy key sanitizes to an empty slug.';
			return $result;
		}

		if ( $old_slug === $target_slug ) {
			$result['notes'] = 'Slug already matches legacy key.';
			if ( ! $dry_run ) {
				delete_post_meta( $id, self::LEGACY_META_KEY );
				$result['status'] = 'migrated';
				$result['notes']  = 'Deleted legacy meta only.';
			}
			return $result;
		}

		if ( $this->slug_is_taken_by_other_post( $target_slug, $id ) ) {
			$result['status'] = 'error';
			$result['notes']  = 'Slug collision: another post already uses this slug.';
			return $result;
		}

		if ( $dry_run ) {
			$result['status'] = 'skipped';
			$result['notes']  = 'Would rename slug and delete legacy meta.';
			return $result;
		}

		$updated = wp_update_post(
			array(
				'ID'        => $id,
				'post_name' => $target_slug,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$result['status'] = 'error';
			$result['notes']  = $updated->get_error_message();
			return $result;
		}

		$result_slug           = (string) get_post_field( 'post_name', $id );
		$result['result_slug'] = $result_slug;

		if ( $result_slug !== $target_slug ) {
			wp_update_post(
				array(
					'ID'        => $id,
					'post_name' => $old_slug,
				),
				true
			);
			$result['result_slug'] = $old_slug;
			$result['status']      = 'error';
			$result['notes']       = 'Slug collision: WordPress assigned a different slug.';
			return $result;
		}

		delete_post_meta( $id, self::LEGACY_META_KEY );
		$result['status'] = 'migrated';
		$result['notes']  = 'Renamed slug and deleted legacy meta.';

		return $result;
	}

	/**
	 * Slug is taken by other post.
	 *
	 * @param string $slug Slug.
	 * @param int    $post_id Post id.
	 */
	private function slug_is_taken_by_other_post( string $slug, int $post_id ): bool {
		$existing = get_page_by_path( $slug, OBJECT, Post_Type::TRANSACTIONAL_POST_TYPE );
		return $existing instanceof \WP_Post && (int) $existing->ID !== $post_id;
	}
}
