/**
 * Modal for generating a weekly links newsletter via AI.
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	SelectControl,
	TextareaControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useAISuggest, AISuggestModal } from '@prc/components';

declare const prcEmailBuilderLibraryAI: {
	enabled: boolean;
	linksNewsletterAbilityName: string;
};

declare const prcEmailLibrary: {
	postEditUrl: string;
	researchTeams?: Array<{
		termId: number;
		slug: string;
		label: string;
	}>;
};

interface GeneratedLinksNewsletter {
	title: string;
	subject: string;
	previewText: string;
	content: string;
}

interface GenerateLinksNewsletterModalProps {
	isOpen: boolean;
	onClose: () => void;
	onDraftCreated?: () => void;
}

export default function GenerateLinksNewsletterModal({
	isOpen,
	onClose,
	onDraftCreated,
}: GenerateLinksNewsletterModalProps) {
	const aiConfig =
		typeof prcEmailBuilderLibraryAI !== 'undefined'
			? prcEmailBuilderLibraryAI
			: null;
	const researchTeams = prcEmailLibrary?.researchTeams ?? [];

	const [additionalPrompt, setAdditionalPrompt] = useState('');
	const [researchTeamId, setResearchTeamId] = useState('0');
	const [lookbackDays, setLookbackDays] = useState('7');
	const [isCreatingDraft, setIsCreatingDraft] = useState(false);
	const [draftError, setDraftError] = useState<string | null>(null);
	const [shouldCreateDraft, setShouldCreateDraft] = useState(false);

	const { isLoading, error, result, fetch, reset, dismissError } =
		useAISuggest<GeneratedLinksNewsletter>({
			abilityName: aiConfig?.linksNewsletterAbilityName ?? '',
			transformResult: (raw) => ({
				title: String(raw.title ?? ''),
				subject: String(raw.subject ?? ''),
				previewText: String(raw.previewText ?? ''),
				content: String(raw.content ?? ''),
			}),
		});

	const closeModal = useCallback(() => {
		setAdditionalPrompt('');
		setResearchTeamId('0');
		setLookbackDays('7');
		setDraftError(null);
		setShouldCreateDraft(false);
		reset();
		onClose();
	}, [onClose, reset]);

	const handleClose = useCallback(() => {
		if (isLoading || isCreatingDraft) {
			return;
		}
		closeModal();
	}, [closeModal, isCreatingDraft, isLoading]);

	const createDraftFromResult = useCallback(
		async (newsletter: GeneratedLinksNewsletter) => {
			setShouldCreateDraft(false);
			setIsCreatingDraft(true);
			setDraftError(null);

			try {
				const post = await apiFetch<{ id: number }>({
					path: '/wp/v2/prc_email_campaign',
					method: 'POST',
					data: {
						title: newsletter.title,
						status: 'draft',
						content: newsletter.content,
						meta: {
							prc_email_subject: newsletter.subject,
							prc_email_preview_text: newsletter.previewText,
						},
					},
				});

				const editUrl = `${prcEmailLibrary.postEditUrl}?post=${post.id}&action=edit`;
				window.open(editUrl, '_blank', 'noopener,noreferrer');
				onDraftCreated?.();
				closeModal();
			} catch (err) {
				const message =
					err instanceof Error
						? err.message
						: __(
								'Failed to create the email draft.',
								'prc-email-builder'
							);
				setDraftError(message);
			} finally {
				setIsCreatingDraft(false);
			}
		},
		[closeModal, onDraftCreated]
	);

	useEffect(() => {
		if (
			!shouldCreateDraft ||
			isLoading ||
			error ||
			!result?.title ||
			!result?.content
		) {
			return;
		}

		void createDraftFromResult(result);
	}, [shouldCreateDraft, isLoading, error, result, createDraftFromResult]);

	const handleGenerate = useCallback(() => {
		setDraftError(null);
		setShouldCreateDraft(true);
		dismissError();

		const input: Record<string, unknown> = {};
		const trimmedPrompt = additionalPrompt.trim();
		if (trimmedPrompt) {
			input.context = trimmedPrompt;
		}
		const teamId = parseInt(researchTeamId, 10);
		if (teamId > 0) {
			input.researchTeamId = teamId;
		}
		const days = Math.min(30, Math.max(7, parseInt(lookbackDays, 10) || 7));
		input.lookbackDays = days;

		void fetch(input);
	}, [additionalPrompt, dismissError, fetch, lookbackDays, researchTeamId]);

	if (!aiConfig?.enabled) {
		return null;
	}

	const teamOptions = [
		{
			label: __('All research teams', 'prc-email-builder'),
			value: '0',
		},
		...researchTeams.map((team) => ({
			label: team.label,
			value: String(team.termId),
		})),
	];

	const combinedError = draftError || error;
	const busy = isLoading || isCreatingDraft;

	return (
		<AISuggestModal
			title={__('Generate Links Newsletter', 'prc-email-builder')}
			isOpen={isOpen}
			onClose={handleClose}
			isLoading={busy}
			loadingMessage={
				isCreatingDraft
					? __('Creating email draft…', 'prc-email-builder')
					: __(
							'Generating weekly links newsletter…',
							'prc-email-builder'
						)
			}
			error={combinedError}
			onDismissError={() => {
				setDraftError(null);
				dismissError();
			}}
			maxWidth="640px"
			footer={
				!busy ? (
					<>
						<Button variant="primary" onClick={handleGenerate}>
							{__('Generate', 'prc-email-builder')}
						</Button>
						<Button variant="tertiary" onClick={handleClose}>
							{__('Cancel', 'prc-email-builder')}
						</Button>
					</>
				) : null
			}
		>
			<p style={{ marginTop: 0, color: '#757575' }}>
				{__(
					'Create a draft newsletter from published content within a configurable lookback window (7–30 days).',
					'prc-email-builder'
				)}
			</p>

			<NumberControl
				label={__('Lookback period (days)', 'prc-email-builder')}
				value={lookbackDays}
				onChange={(value) => setLookbackDays(value ?? '7')}
				min={7}
				max={30}
				step={1}
				help={__(
					'How far back to search for published source content.',
					'prc-email-builder'
				)}
				__nextHasNoMarginBottom
			/>

			<SelectControl
				label={__('Research team', 'prc-email-builder')}
				value={researchTeamId}
				options={teamOptions}
				onChange={setResearchTeamId}
				help={__(
					'Optionally limit source content to a single research team.',
					'prc-email-builder'
				)}
				__nextHasNoMarginBottom
			/>

			<TextareaControl
				label={__('Additional instructions', 'prc-email-builder')}
				value={additionalPrompt}
				onChange={setAdditionalPrompt}
				rows={4}
				help={__(
					'Optional context for tone, emphasis, or sections to include.',
					'prc-email-builder'
				)}
				__nextHasNoMarginBottom
			/>
		</AISuggestModal>
	);
}
