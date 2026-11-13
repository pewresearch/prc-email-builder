import { useCallback } from '@wordpress/element';
import { useRegistry } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';

import { withServerMeta, type PostRecord } from './with-server-meta';

/**
 * Show a value the server owns without sending it back on the next save.
 *
 * `prc_email_mailchimp_pending_send_at` is written only by the server. The
 * server ignores an editor write of it. editPost() also puts the key into the
 * unsaved edits of the tab, and every later save then sends that stale value.
 * This hook updates the saved record in core-data instead, so the edits stay
 * clean and the sidebar still reads the new value.
 *
 * @param postId Newsletter post ID.
 */
export function useApplyServerMeta(postId: number) {
	const registry = useRegistry();

	return useCallback(
		(patch: Record<string, string>) => {
			const postType = registry.select(editorStore).getCurrentPostType();
			const record = registry
				.select(coreStore)
				.getEntityRecord('postType', postType, postId) as
				| PostRecord
				| undefined;
			if (!postType || !record) {
				return;
			}
			registry
				.dispatch(coreStore)
				.receiveEntityRecords(
					'postType',
					postType,
					withServerMeta(record, patch),
					undefined,
					false
				);
		},
		[registry, postId]
	);
}
