/**
 * Subject line field with optional AI suggestions.
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

interface InboxSubjectAIProps {
	postId: number;
	subject: string;
	onChange: (subject: string) => void;
	onApply: (subject: string) => void;
}

export function InboxSubjectAI({
	postId,
	subject,
	onChange,
	onApply,
}: InboxSubjectAIProps) {
	const aiConfig =
		typeof prcEmailBuilderAI !== 'undefined' ? prcEmailBuilderAI : null;

	const buildFetchInput = useCallback(() => ({ postId }), [postId]);

	const label = __('Subject line', 'prc-email-builder');

	return (
		<InboxMetadataField
			label={label}
			value={subject}
			onChange={onChange}
			placeholder={__('Enter email subject…', 'prc-email-builder')}
			aiControl={
				aiConfig?.enabled ? (
					<InboxAISuggest
						postId={postId}
						abilityName={aiConfig.subjectAbilityName}
						buttonLabel={__(
							'Suggest subject with AI',
							'prc-email-builder'
						)}
						modalTitle={__(
							'Subject line suggestions',
							'prc-email-builder'
						)}
						loadingMessage={__(
							'Generating subject lines…',
							'prc-email-builder'
						)}
						buildFetchInput={buildFetchInput}
						transformResult={(raw) =>
							(raw.options as { subject: string }[]).map(
								(o) => o.subject
							)
						}
						onApply={onApply}
					/>
				) : undefined
			}
		/>
	);
}
