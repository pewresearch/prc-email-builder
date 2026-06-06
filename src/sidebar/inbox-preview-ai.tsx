/**
 * Preview text field with optional AI suggestions.
 */

import { __ } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';

import { InboxAISuggest } from './inbox-ai-suggest';
import { InboxMetadataField } from './inbox-metadata-field';

declare const prcEmailBuilderAI: {
	enabled: boolean;
	subjectAbilityName: string;
	previewAbilityName: string;
};

interface InboxPreviewAIProps {
	postId: number;
	previewText: string;
	currentSubject: string;
	onChange: (previewText: string) => void;
	onApply: (previewText: string) => void;
}

export function InboxPreviewAI({
	postId,
	previewText,
	currentSubject,
	onChange,
	onApply,
}: InboxPreviewAIProps) {
	const aiConfig =
		typeof prcEmailBuilderAI !== 'undefined' ? prcEmailBuilderAI : null;

	const buildFetchInput = useCallback(
		() => ({
			postId,
			currentSubject: currentSubject || undefined,
		}),
		[postId, currentSubject]
	);

	const label = __('Preview text', 'prc-email-builder');

	return (
		<InboxMetadataField
			label={label}
			value={previewText}
			onChange={onChange}
			placeholder={__(
				'Short preview shown in inbox…',
				'prc-email-builder'
			)}
			help={__(
				'Appears after the subject line in most email clients.',
				'prc-email-builder'
			)}
			aiControl={
				aiConfig?.enabled ? (
					<InboxAISuggest
						postId={postId}
						abilityName={aiConfig.previewAbilityName}
						buttonLabel={__(
							'Suggest preview text with AI',
							'prc-email-builder'
						)}
						modalTitle={__(
							'Preview text suggestions',
							'prc-email-builder'
						)}
						loadingMessage={__(
							'Generating preview text…',
							'prc-email-builder'
						)}
						buildFetchInput={buildFetchInput}
						transformResult={(raw) =>
							(raw.options as { previewText: string }[]).map(
								(o) => o.previewText
							)
						}
						onApply={onApply}
					/>
				) : undefined
			}
		/>
	);
}
