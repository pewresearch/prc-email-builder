import {
	Button,
	Flex,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	canStartAudience,
	isDomainNeedleValid,
	type StartAudienceInput,
} from './auth-domain-audience-types';
import { useAuthDomainAudienceJob } from './use-auth-domain-audience-job';

interface AuthDomainAudienceModalProps {
	readonly isOpen: boolean;
	readonly onClose: () => void;
}

const INITIAL_INPUT: StartAudienceInput = {
	domainContains: '',
	verification: 'verified',
	label: '',
};

export function AuthDomainAudienceModal({
	isOpen,
	onClose,
}: AuthDomainAudienceModalProps) {
	const [step, setStep] = useState(0);
	const [input, setInput] = useState<StartAudienceInput>(INITIAL_INPUT);
	const {
		view,
		error,
		isStarting,
		isCreatingDraft,
		start,
		createDraft,
		reset,
	} = useAuthDomainAudienceJob(isOpen);

	if (!isOpen) {
		return null;
	}

	const handleClose = () => {
		setStep(0);
		setInput(INITIAL_INPUT);
		reset();
		onClose();
	};

	const handleStart = () => {
		if (canStartAudience(input)) {
			void start(input);
		}
	};

	return (
		<Modal
			title={__('Build audience', 'prc-email-builder')}
			onRequestClose={handleClose}
		>
			<Flex direction="column" gap={4} align="stretch">
				{view === null && !isStarting ? (
					<AudienceForm
						step={step}
						input={input}
						onChange={setInput}
					/>
				) : null}

				{isStarting ||
				view?.phase === 'queued' ||
				view?.phase === 'scanning' ? (
					<div>
						<p>
							{__(
								'Building this audience can take several minutes. The recipient count appears when the build finishes.',
								'prc-email-builder'
							)}
						</p>
						<p>
							{__(
								'You can close this dialog. The list appears in the recipient picker when the build finishes.',
								'prc-email-builder'
							)}
						</p>
					</div>
				) : null}

				{view?.phase === 'ready' ? (
					<div>
						<p>
							<strong>{view.audience.label}</strong>
						</p>
						<p>
							{sprintf(
								/* translators: %s: audience recipient count */
								__('%s recipients', 'prc-email-builder'),
								view.audience.count.toLocaleString()
							)}
						</p>
						{view.draft.status === 'failed' ? (
							<p role="alert">{view.draft.message}</p>
						) : null}
					</div>
				) : null}

				{view?.phase === 'failed' ? (
					<p role="alert">{view.error.message}</p>
				) : null}

				{error ? <p role="alert">{error}</p> : null}

				<Flex justify="flex-end" gap={2}>
					{view === null && !isStarting && step > 0 ? (
						<Button
							variant="secondary"
							onClick={() => setStep(step - 1)}
						>
							{__('Back', 'prc-email-builder')}
						</Button>
					) : null}
					{view === null && !isStarting && step < 2 ? (
						<Button
							variant="primary"
							disabled={
								step === 0 &&
								!isDomainNeedleValid(input.domainContains)
							}
							onClick={() => setStep(step + 1)}
						>
							{__('Next', 'prc-email-builder')}
						</Button>
					) : null}
					{view === null && !isStarting && step === 2 ? (
						<Button
							variant="primary"
							disabled={!canStartAudience(input)}
							onClick={handleStart}
						>
							{__('Build audience', 'prc-email-builder')}
						</Button>
					) : null}
					{view?.phase === 'ready' &&
					view.draft.status !== 'created' ? (
						<Button
							variant="primary"
							isBusy={isCreatingDraft}
							disabled={isCreatingDraft}
							onClick={() => void createDraft()}
						>
							{__(
								'Create transactional email',
								'prc-email-builder'
							)}
						</Button>
					) : null}
					{view?.phase === 'ready' &&
					view.draft.status === 'created' ? (
						<Button variant="primary" href={view.draft.editUrl}>
							{__(
								'Open transactional email',
								'prc-email-builder'
							)}
						</Button>
					) : null}
					<Button variant="secondary" onClick={handleClose}>
						{view?.phase === 'ready'
							? __('Close', 'prc-email-builder')
							: __('Cancel', 'prc-email-builder')}
					</Button>
				</Flex>
			</Flex>
		</Modal>
	);
}

interface AudienceFormProps {
	readonly step: number;
	readonly input: StartAudienceInput;
	readonly onChange: (input: StartAudienceInput) => void;
}

function AudienceForm({ step, input, onChange }: AudienceFormProps) {
	if (step === 0) {
		return (
			<TextControl
				label={__('Domain contains', 'prc-email-builder')}
				help={__(
					'Enter part of the email domain, such as k12. Do not include @.',
					'prc-email-builder'
				)}
				value={input.domainContains}
				onChange={(domainContains) =>
					onChange({ ...input, domainContains })
				}
				__nextHasNoMarginBottom
			/>
		);
	}

	if (step === 1) {
		return (
			<SelectControl
				label={__('Email verification', 'prc-email-builder')}
				value={input.verification}
				options={[
					{
						label: __('Verified only', 'prc-email-builder'),
						value: 'verified',
					},
					{
						label: __('Unverified only', 'prc-email-builder'),
						value: 'unverified',
					},
					{
						label: __('All users', 'prc-email-builder'),
						value: 'all',
					},
				]}
				onChange={(verification) =>
					handleVerificationChange(verification, input, onChange)
				}
				__nextHasNoMarginBottom
			/>
		);
	}

	return (
		<TextControl
			label={__('Audience name', 'prc-email-builder')}
			help={__(
				'This name appears in the recipient list picker.',
				'prc-email-builder'
			)}
			value={input.label}
			onChange={(label) => onChange({ ...input, label })}
			__nextHasNoMarginBottom
		/>
	);
}

function handleVerificationChange(
	verification: string,
	input: StartAudienceInput,
	onChange: (input: StartAudienceInput) => void
) {
	if (
		verification === 'verified' ||
		verification === 'unverified' ||
		verification === 'all'
	) {
		onChange({ ...input, verification });
	}
}
