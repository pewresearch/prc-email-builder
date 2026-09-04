import {
	Button,
	Card,
	CardBody,
	Flex,
	Spinner,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

import {
	groupCatalogRows,
	type AudienceBuilder,
	type AudienceCatalogRow,
} from './audience-catalog';
import type {
	StartAudienceInput,
	VerificationMode,
} from './auth-domain-audience-types';
import { DomainQueryForm } from './domain-query-form';
import { CsvUploadForm, type CsvUploadInput } from './csv-upload-form';
import { SourceEntityForm } from './source-entity-form';
import type { AudienceJobView } from './use-audience-job';

export function CatalogView({
	builders,
	rows,
	error,
}: {
	readonly builders: AudienceBuilder[];
	readonly rows: AudienceCatalogRow[] | null;
	readonly error: string | null;
}) {
	if (error) {
		return <p role="alert">{error}</p>;
	}
	if (rows === null) {
		return <Spinner />;
	}

	const groups = groupCatalogRows(rows, builders);

	if (groups.length === 0) {
		return (
			<p>
				{__(
					'No recipient lists yet. Build a new audience to add one.',
					'prc-email-builder'
				)}
			</p>
		);
	}

	return (
		<VStack spacing={4}>
			{groups.map((group) => (
				<VStack key={group.slug} spacing={2}>
					<h3>{group.label}</h3>
					{group.rows.map((row) => (
						<CatalogRow key={row.key} row={row} />
					))}
				</VStack>
			))}
		</VStack>
	);
}

export function BuildNewButton({ onClick }: { readonly onClick: () => void }) {
	return (
		<Button
			__next40pxDefaultSize
			variant="primary"
			icon={plus}
			iconPosition="right"
			className="prc-email-audience-hub__build-new"
			onClick={onClick}
		>
			{__('Build new', 'prc-email-builder')}
		</Button>
	);
}

function CatalogRow({ row }: { readonly row: AudienceCatalogRow }) {
	const [isCreating, setIsCreating] = useState(false);
	const [error, setError] = useState<string | null>(null);

	return (
		<Card>
			<CardBody>
				<strong>{row.label}</strong>
				<p>
					{sprintf(
						/* translators: %s: audience recipient count */
						__('%s recipients', 'prc-email-builder'),
						row.count.toLocaleString()
					)}
				</p>
				{error ? <p role="alert">{error}</p> : null}
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isBusy={isCreating}
					disabled={isCreating}
					onClick={() => {
						setIsCreating(true);
						setError(null);
						void apiFetch<{ edit_url?: string }>({
							path: '/prc-email-builder/v1/transactional/create-from-audience',
							method: 'POST',
							data: { audience_key: row.key },
						})
							.then((result) => {
								if (result?.edit_url) {
									window.location.assign(result.edit_url);
									return;
								}
								setError(
									__(
										'Draft created but no editor URL was returned.',
										'prc-email-builder'
									)
								);
							})
							.catch((reason: unknown) => {
								setError(getHubErrorMessage(reason));
							})
							.finally(() => {
								setIsCreating(false);
							});
					}}
				>
					{__('Create transactional email', 'prc-email-builder')}
				</Button>
			</CardBody>
		</Card>
	);
}

export function BuilderPicker({
	builders,
	onSelect,
}: {
	readonly builders: AudienceBuilder[];
	readonly onSelect: (builder: AudienceBuilder) => void;
}) {
	if (builders.length === 0) {
		return (
			<p>
				{__(
					'No audience builders are registered.',
					'prc-email-builder'
				)}
			</p>
		);
	}

	return (
		<Flex direction="column" gap={3} align="stretch">
			<p>
				{__(
					'Choose the kind of recipient list to build.',
					'prc-email-builder'
				)}
			</p>
			{builders.map((builder) => (
				<Button
					key={builder.slug}
					__next40pxDefaultSize
					variant="secondary"
					onClick={() => onSelect(builder)}
				>
					{builder.label}
					<span className="components-base-control__help">
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
									'You can close this dialog. The list appears in the catalog and recipient picker when the build finishes.',
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

export function getHubErrorMessage(reason: unknown): string {
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

export function hubCloseLabel(phase: string | undefined): string {
	if (phase === 'queued' || phase === 'scanning' || phase === 'ready') {
		return __('Close', 'prc-email-builder');
	}
	return __('Cancel', 'prc-email-builder');
}
