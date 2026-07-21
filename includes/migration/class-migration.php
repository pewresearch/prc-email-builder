<?php
/**
 * Migrated-from-Newsletter-Glue marker helper.
 *
 * Posts created by the retired NGL → email-builder migration carry
 * `_migrated_from_ngl_id` meta. Call sites use `is_migrated()` to skip
 * send/sync/reporting for those archival posts.
 *
 * @package PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Marker helpers for posts migrated from Newsletter Glue.
 */
class Migration {

	/**
	 * Post meta key linking a campaign/txn post to its source NGL post ID.
	 */
	const MIGRATED_META_KEY = '_migrated_from_ngl_id';

	/**
	 * Whether a post was created by the NGL migration.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if the post has the migration marker meta.
	 */
	public static function is_migrated( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, self::MIGRATED_META_KEY, true );
	}
}
