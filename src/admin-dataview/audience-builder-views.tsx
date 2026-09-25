import { Button, Flex } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';

import type { AudienceBuilder } from './audience-catalog';
import type {
	StartAudienceInput,
	VerificationMode,
} from './auth-domain-audience-types';
import { DomainQueryForm } from './domain-query-form';
import { CsvUploadForm, type CsvUploadInput } from './csv-upload-form';
import { SourceEntityForm } from './source-entity-form';
import type { AudienceJobView } from './use-audience-job';

export function BuilderPicker({
	builders,
	onSelect,
	onSelectNewsletterList,
}: {
	readonly builders: AudienceBuilder[];
	readonly onSelect: (builder: AudienceBuilder) => void;
	readonly onSelectNewsletterList?: () => void;
}) {
	return (
		<Flex direction="column" gap={3} align="stretch">
			{onSelectNewsletterList ? (
				<>
					<h3 className="prc-email-audience-builder__section-title">
						{__('Newsletter list', 'prc-email-builder')}
					</h3>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						className="prc-email-audience-builder__option"
						onClick={onSelectNewsletterList}
					>
						<span className="prc-email-audience-builder__option-label">
							{__(
								'Mailchimp newsletter list',
								'prc-email-builder'
							)}
						</span>
						<span className="prc-email-audience-builder__option-description">
							{__(
								'Connect a Mailchimp audience or segment. Campaigns use it for recipients and sender defaults.',
								'prc-email-builder'
							)}
						</span>
					</Button>
				</>
			) : null}
			<h3 className="prc-email-audience-builder__section-title">
				{__('Recipient list', 'prc-email-builder')}
			</h3>
			{builders.length === 0 ? (
				<p>
					{__(
						'No audience builders are registered.',
						'prc-email-builder'
					)}
				</p>
			) : (
				<p>
					{__(
						'Build a fixed list of addresses for bulk transactional emails.',
						'prc-email-builder'
					)}
				</p>
			)}
			{builders.map((builder) => (
				<Button
					key={builder.slug}
					__next40pxDefaultSize
					variant="secondary"
					className="prc-email-audience-builder__option"
					onClick={() => onSelect(builder)}
				>
					<span className="prc-email-audience-builder__option-label">
						{builder.label}
					</span>
					<span className="prc-email-audience-builder__option-description">
						{builder.description}
					</span>
				</Button>
			))}
		</Flex>
	);
}

export function BuilderForm({
	builder,
	domainStep,
	domainInput,
	onDomainChange,
	sourceId,
	sourceName,
	sourceVerification,
	sourceLabel,
	csvInput,
	onCsvChange,
	onSourceChange,
}: {
	readonly builder: AudienceBuilder;
	readonly domainStep: number;
	readonly domainInput: StartAudienceInput;
	readonly onDomainChange: (input: StartAudienceInput) => void;
	readonly sourceId: number;
	readonly sourceName: string;
	readonly sourceVerification: VerificationMode;
	readonly sourceLabel: string;
	readonly csvInput: CsvUploadInput;
	readonly onCsvChange: (input: CsvUploadInput) => void;
	readonly onSourceChange: (next: {
		sourceId: number;
		sourceName: string;
		verification: VerificationMode;
		label: string;
	}) => void;
}) {
	let DefaultForm:
		| typeof DomainQueryFormSlot
		| typeof CsvUploadFormSlot
		| typeof SourceEntityForm = SourceEntityForm;
	if (builder.form === 'domain-query') {
		DefaultForm = DomainQueryFormSlot;
	} else if (builder.form === 'csv-upload') {
		DefaultForm = CsvUploadFormSlot;
	}
	const Renderer = applyFilters(
		'prcEmailAudience.renderBuilder',
		DefaultForm,
		builder
	) as typeof DefaultForm;

	if (builder.form === 'domain-query') {
		return (
			<Renderer
				builder={builder}
				step={domainStep}
				input={domainInput}
				onChange={onDomainChange}
			/>
		);
	}

	if (builder.form === 'csv-upload') {
		return (
			<Renderer
				builder={builder}
				input={csvInput}
				onChange={onCsvChange}
			/>
		);
	}

	return (
		<Renderer
			builder={builder}
			sourceId={sourceId}
			sourceName={sourceName}
			verification={sourceVerification}
			label={sourceLabel}
			onChange={onSourceChange}
		/>
	);
}

function DomainQueryFormSlot({
	step,
	input,
	onChange,
}: {
	readonly builder: AudienceBuilder;
	readonly step: number;
	readonly input: StartAudienceInput;
	readonly onChange: (input: StartAudienceInput) => void;
}) {
	return <DomainQueryForm step={step} input={input} onChange={onChange} />;
}

function CsvUploadFormSlot({
	input,
	onChange,
}: {
	readonly builder: AudienceBuilder;
	readonly input: CsvUploadInput;
	readonly onChange: (input: CsvUploadInput) => void;
}) {
	return <CsvUploadForm input={input} onChange={onChange} />;
}

export function JobProgress({
	isStarting,
	view,
	error,
	isLocalBuild = false,
}: {
	readonly isStarting: boolean;
	readonly view: AudienceJobView | null;
	readonly error: string | null;
	readonly isLocalBuild?: boolean;
}) {
	return (
		<>
			{isStarting ||
			view?.phase === 'queued' ||
			view?.phase === 'scanning' ? (
				<div>
					{isLocalBuild ? (
						<p>
							{__(
								'Saving this recipient list.',
								'prc-email-builder'
							)}
						</p>
					) : (
						<>
							<p>
								{__(
									'Building this audience can take several minutes. The recipient count appears when the build finishes.',
									'prc-email-builder'
								)}
							</p>
							<p>
								{__(
									'You can close this dialog. The list appears on this page and in the recipient picker when the build finishes.',
									'prc-email-builder'
								)}
							</p>
						</>
					)}
				</div>
			) : null}
			{view?.phase === 'ready' && view.audience ? (
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
					{view.draft?.status === 'failed' ? (
						<p role="alert">{view.draft.message}</p>
					) : null}
				</div>
			) : null}
			{view?.phase === 'failed' ? (
				<p role="alert">{view.error?.message}</p>
			) : null}
			{error ? <p role="alert">{error}</p> : null}
		</>
	);
}

export function getAudienceErrorMessage(reason: unknown): string {
	if (reason instanceof Error && reason.message) {
		return reason.message;
	}
	if (
		typeof reason === 'object' &&
		reason !== null &&
		'message' in reason &&
		typeof reason.message === 'string' &&
		reason.message !== ''
	) {
		return reason.message;
	}
	return 'The audience request failed.';
}

export function builderCloseLabel(phase: string | undefined): string {
	if (phase === 'queued' || phase === 'scanning' || phase === 'ready') {
		return __('Close', 'prc-email-builder');
	}
	return __('Cancel', 'prc-email-builder');
}
