/**
 * Email Preview — View-menu item + modal.
 *
 * Adds "Email Preview" to the editor View menu (PluginPreviewMenuItem slot).
 * The menu item is only rendered on email campaign and transactional posts.
 */

import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore, PluginPreviewMenuItem } from '@wordpress/editor';
import { atSymbol } from '@wordpress/icons';

import { PreviewModal } from './preview-modal';

export function EmailPreviewMenuItem() {
	const [isOpen, setIsOpen] = useState(false);

	const postId: number = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	return (
		<>
			<PluginPreviewMenuItem
				icon={atSymbol}
				onClick={() => setIsOpen(true)}
				data-prc-tour="email-preview-menu"
			>
				{__('Email Preview', 'prc-email-builder')}
			</PluginPreviewMenuItem>

			{isOpen && (
				<PreviewModal
					postId={postId}
					onClose={() => setIsOpen(false)}
				/>
			)}
		</>
	);
}
