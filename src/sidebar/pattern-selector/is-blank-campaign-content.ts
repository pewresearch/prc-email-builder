/**
 * Detect whether an email editor has no meaningful block content yet.
 */

import type { BlockInstance } from '@wordpress/blocks';

function isEmptyParagraph(block: BlockInstance): boolean {
	if (block.name !== 'core/paragraph') {
		return false;
	}

	const content = block.attributes?.content;
	return typeof content !== 'string' || content.trim() === '';
}

export function isBlankEmailContent(
	blocks: BlockInstance[] | null | undefined
): boolean {
	if (!blocks || blocks.length === 0) {
		return true;
	}

	if (blocks.length === 1 && isEmptyParagraph(blocks[0])) {
		return true;
	}

	return false;
}

/** @deprecated Use isBlankEmailContent */
export const isBlankCampaignContent = isBlankEmailContent;

export function getPatternSelectorDismissKey(postId: number): string {
	return `prc-email-pattern-selector-dismissed-${postId}`;
}

export function isPatternSelectorDismissed(postId: number): boolean {
	try {
		return (
			window.sessionStorage.getItem(
				getPatternSelectorDismissKey(postId)
			) === '1'
		);
	} catch {
		return false;
	}
}

export function dismissPatternSelector(postId: number): void {
	try {
		window.sessionStorage.setItem(
			getPatternSelectorDismissKey(postId),
			'1'
		);
	} catch {
		// Ignore storage failures; the modal can still close for this session.
	}
}
