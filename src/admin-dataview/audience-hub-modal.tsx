import { Button, Flex, Modal } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { AudienceBuilder, AudienceCatalogRow } from './audience-catalog';
import {
	canStartAudience,
	type StartAudienceInput,
	type VerificationMode,
} from './auth-domain-audience-types';
import { canStartCsvUpload, type CsvUploadInput } from './csv-upload-form';
import { parseCsvEmails } from './csv-email-list';
import { canAdvanceDomainStep } from './domain-query-form';
import { getEmailConfig } from '../library/types';
import { canStartSourceEntity } from './source-entity-form';
import { useAudienceJob } from './use-audience-job';
import {
	BuildNewButton,
	BuilderForm,
	BuilderPicker,
	CatalogView,
	getHubErrorMessage,
	hubCloseLabel,
	JobProgress,
} from './audience-hub-views';

interface AudienceHubModalProps {
	readonly isOpen: boolean;
	readonly onClose: () => void;
}

type HubScreen = 'catalog' | 'pick-builder' | 'form' | 'job';

const INITIAL_DOMAIN: StartAudienceInput = {
	domainContains: '',
	verification: 'verified',
	label: '',
};

const INITIAL_CSV: CsvUploadInput = {
	label: '',
	csv: '',
	fileName: '',
};

export function AudienceHubModal({ isOpen, onClose }: AudienceHubModalProps) {
	const builders = getEmailConfig()?.audienceBuilders ?? [];
	const [screen, setScreen] = useState<HubScreen>('catalog');
	const [catalog, setCatalog] = useState<AudienceCatalogRow[] | null>(null);
	const [catalogError, setCatalogError] = useState<string | null>(null);
	const [builder, setBuilder] = useState<AudienceBuilder | null>(null);
	const [domainStep, setDomainStep] = useState(0);
	const [domainInput, setDomainInput] =
		useState<StartAudienceInput>(INITIAL_DOMAIN);
	const [sourceId, setSourceId] = useState(0);
	const [sourceName, setSourceName] = useState('');
	const [sourceVerification, setSourceVerification] =
		useState<VerificationMode>('verified');
	const [sourceLabel, setSourceLabel] = useState('');
	const [csvInput, setCsvInput] = useState<CsvUploadInput>(INITIAL_CSV);
	const {
		view,
		error,
		isStarting,
		isCreatingDraft,
		start,
		createDraft,
		reset,
	} = useAudienceJob(isOpen);

	useEffect(() => {
		if (!isOpen || screen !== 'catalog') {
			return;
		}
		let cancelled = false;
		setCatalogError(null);
		void apiFetch<AudienceCatalogRow[]>({
			path: '/prc-email-builder/v1/audiences-system',
		})
			.then((rows) => {
				if (!cancelled) {
					setCatalog(Array.isArray(rows) ? rows : []);
				}
			})
			.catch((reason: unknown) => {
				if (!cancelled) {
					setCatalogError(getHubErrorMessage(reason));
				}
			});
		return () => {
			cancelled = true;
		};
	}, [isOpen, screen]);

	useEffect(() => {
		if (view !== null || isStarting) {
			setScreen('job');
		}
	}, [view, isStarting]);

	if (!isOpen) {
		return null;
	}

	const handleClose = () => {
		setScreen('catalog');
		setBuilder(null);
		setDomainStep(0);
		setDomainInput(INITIAL_DOMAIN);
		setSourceId(0);
		setSourceName('');
		setSourceVerification('verified');
		setSourceLabel('');
		setCsvInput(INITIAL_CSV);
		reset();
		onClose();
	};

	const handleStart = () => {
		if (!builder) {
			return;
		}
		if (builder.form === 'domain-query') {
			if (canStartAudience(domainInput)) {
				void start({
					builder: builder.slug,
					...domainInput,
				});
			}
			return;
		}
		if (builder.form === 'csv-upload') {
			if (
				canStartCsvUpload(csvInput.label, parseCsvEmails(csvInput.csv))
			) {
				void start({
					builder: builder.slug,
					label: csvInput.label,
					csv: csvInput.csv,
				});
			}
			return;
		}
		if (!canStartSourceEntity(sourceId) || !builder.sourceIdParam) {
			return;
		}
		void start({
			builder: builder.slug,
			[builder.sourceIdParam]: sourceId,
			verification: sourceVerification,
			label: sourceLabel,
		});
	};

	return (
		<Modal
			title={__('Build audience', 'prc-email-builder')}
			onRequestClose={handleClose}
			className="prc-email-audience-hub"
			size="medium"
		>
			<div className="prc-email-audience-hub__layout">
				<div className="prc-email-audience-hub__body">
					{screen === 'catalog' ? (
						<CatalogView
							builders={builders}
							rows={catalog}
							error={catalogError}
						/>
					) : null}

					{screen === 'pick-builder' ? (
						<BuilderPicker
							builders={builders}
							onSelect={(next) => {
								if (builder?.slug !== next.slug) {
									setSourceId(0);
									setSourceName('');
									setSourceVerification('verified');
									setSourceLabel('');
									setCsvInput(INITIAL_CSV);
								}
								setBuilder(next);
								setScreen('form');
							}}
						/>
					) : null}

					{screen === 'form' && builder ? (
						<BuilderForm
							builder={builder}
							domainStep={domainStep}
							domainInput={domainInput}
							onDomainChange={setDomainInput}
							sourceId={sourceId}
							sourceName={sourceName}
							sourceVerification={sourceVerification}
							sourceLabel={sourceLabel}
							csvInput={csvInput}
							onCsvChange={setCsvInput}
							onSourceChange={({
								sourceId: nextId,
								sourceName: nextName,
								verification,
								label,
							}) => {
								setSourceId(nextId);
								setSourceName(nextName);
								setSourceVerification(verification);
								setSourceLabel(label);
							}}
						/>
					) : null}

					{screen === 'job' ? (
						<JobProgress
							isStarting={isStarting}
							view={view}
							error={error}
							isLocalBuild={builder?.form === 'csv-upload'}
						/>
					) : null}
				</div>

				<HubFooter
					screen={screen}
					builder={builder}
					domainStep={domainStep}
					domainInput={domainInput}
					sourceId={sourceId}
					csvInput={csvInput}
					view={view}
					isCreatingDraft={isCreatingDraft}
					onBuildNew={() => setScreen('pick-builder')}
					onBackToCatalog={() => setScreen('catalog')}
					onBackDomain={() => setDomainStep(domainStep - 1)}
					onNextDomain={() => setDomainStep(domainStep + 1)}
					onBackToPicker={() => setScreen('pick-builder')}
					onStart={handleStart}
					onCreateDraft={() => void createDraft()}
					onClose={handleClose}
				/>
			</div>
		</Modal>
	);
}

function HubFooter({
	screen,
	builder,
	domainStep,
	domainInput,
	sourceId,
	csvInput,
	view,
	isCreatingDraft,
	onBuildNew,
	onBackToCatalog,
	onBackDomain,
	onNextDomain,
	onBackToPicker,
	onStart,
	onCreateDraft,
	onClose,
}: {
	readonly screen: HubScreen;
	readonly builder: AudienceBuilder | null;
	readonly domainStep: number;
	readonly domainInput: StartAudienceInput;
	readonly sourceId: number;
	readonly csvInput: CsvUploadInput;
	readonly view: ReturnType<typeof useAudienceJob>['view'];
	readonly isCreatingDraft: boolean;
	readonly onBuildNew: () => void;
	readonly onBackToCatalog: () => void;
	readonly onBackDomain: () => void;
	readonly onNextDomain: () => void;
	readonly onBackToPicker: () => void;
	readonly onStart: () => void;
	readonly onCreateDraft: () => void;
	readonly onClose: () => void;
}) {
	const showDomainBack =
		screen === 'form' && builder?.form === 'domain-query' && domainStep > 0;
	const showDomainNext =
		screen === 'form' && builder?.form === 'domain-query' && domainStep < 2;
	const showDomainStart =
		screen === 'form' &&
		builder?.form === 'domain-query' &&
		domainStep === 2;
	const showSourceActions =
		screen === 'form' && builder?.form === 'source-entity';
	const showCsvActions = screen === 'form' && builder?.form === 'csv-upload';
	const showCreateDraft =
		view?.phase === 'ready' &&
		view.draft &&
		view.draft.status !== 'created' &&
		builder?.supportsCreateDraft !== false;
	const showOpenDraft =
		view?.phase === 'ready' &&
		view.draft &&
		view.draft.status === 'created';

	return (
		<Flex
			className="prc-email-audience-hub__footer"
			justify="flex-end"
			gap={2}
		>
			{screen === 'catalog' ? (
				<BuildNewButton onClick={onBuildNew} />
			) : null}
			{screen === 'pick-builder' ? (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={onBackToCatalog}
				>
					{__('Back', 'prc-email-builder')}
				</Button>
			) : null}
			{showDomainBack ? (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={onBackDomain}
				>
					{__('Back', 'prc-email-builder')}
				</Button>
			) : null}
			{showDomainNext ? (
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={!canAdvanceDomainStep(domainStep, domainInput)}
					onClick={onNextDomain}
				>
					{__('Next', 'prc-email-builder')}
				</Button>
			) : null}
			{showDomainStart ? (
				<Button
					__next40pxDefaultSize
					variant="primary"
					disabled={!canStartAudience(domainInput)}
					onClick={onStart}
				>
					{__('Build audience', 'prc-email-builder')}
				</Button>
			) : null}
			{showSourceActions ? (
				<>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={onBackToPicker}
					>
						{__('Back', 'prc-email-builder')}
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={!canStartSourceEntity(sourceId)}
						onClick={onStart}
					>
						{__('Build audience', 'prc-email-builder')}
					</Button>
				</>
			) : null}
			{showCsvActions ? (
				<>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						onClick={onBackToPicker}
					>
						{__('Back', 'prc-email-builder')}
					</Button>
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={
							!canStartCsvUpload(
								csvInput.label,
								parseCsvEmails(csvInput.csv)
							)
						}
						onClick={onStart}
					>
						{__('Build audience', 'prc-email-builder')}
					</Button>
				</>
			) : null}
			{showCreateDraft ? (
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={isCreatingDraft}
					disabled={isCreatingDraft}
					onClick={onCreateDraft}
				>
					{__('Create transactional email', 'prc-email-builder')}
				</Button>
			) : null}
			{showOpenDraft ? (
				<Button
					__next40pxDefaultSize
					variant="primary"
					href={
						view?.draft?.status === 'created'
							? view.draft.editUrl
							: undefined
					}
				>
					{__('Open transactional email', 'prc-email-builder')}
				</Button>
			) : null}
			<Button __next40pxDefaultSize variant="secondary" onClick={onClose}>
				{hubCloseLabel(view?.phase)}
			</Button>
		</Flex>
	);
}
