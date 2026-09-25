import { Button, Flex, Modal, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { AudienceBuilder } from './audience-catalog';
import {
	NewsletterListCreated,
	NewsletterListFooter,
	NewsletterListForm,
} from './newsletter-list-form';
import { useNewsletterListCreation } from './use-newsletter-list-creation';
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
	BuilderForm,
	BuilderPicker,
	builderCloseLabel,
	JobProgress,
} from './audience-builder-views';

export const LIST_REFRESH_EVENT = 'prcWpAdminDataview.refresh';

interface AudienceBuilderModalProps {
	readonly isOpen: boolean;
	readonly onClose: () => void;
}

type BuilderScreen =
	| 'pick-builder'
	| 'form'
	| 'job'
	| 'newsletter-list'
	| 'newsletter-list-done';

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

function refreshAudienceList() {
	window.dispatchEvent(new CustomEvent(LIST_REFRESH_EVENT));
}

export function AudienceBuilderModal({
	isOpen,
	onClose,
}: AudienceBuilderModalProps) {
	const config = getEmailConfig();
	const builders = config?.audienceBuilders ?? [];
	const [screen, setScreen] = useState<BuilderScreen>('pick-builder');
	const newsletterList = useNewsletterListCreation(refreshAudienceList);
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
		if (view !== null || isStarting) {
			setScreen('job');
		}
	}, [view, isStarting]);

	const phase = view?.phase;
	useEffect(() => {
		if (phase === 'ready') {
			refreshAudienceList();
		}
	}, [phase]);

	if (!isOpen) {
		return null;
	}

	const handleClose = () => {
		if (view !== null) {
			refreshAudienceList();
		}
		setScreen('pick-builder');
		setBuilder(null);
		setDomainStep(0);
		setDomainInput(INITIAL_DOMAIN);
		setSourceId(0);
		setSourceName('');
		setSourceVerification('verified');
		setSourceLabel('');
		setCsvInput(INITIAL_CSV);
		newsletterList.reset();
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
			title={__('Add new audience', 'prc-email-builder')}
			onRequestClose={handleClose}
			className="prc-email-audience-builder"
			size="medium"
		>
			<div className="prc-email-audience-builder__layout">
				<div className="prc-email-audience-builder__body">
					{screen === 'pick-builder' ? (
						<BuilderPicker
							builders={builders}
							onSelectNewsletterList={
								config?.canManageLists
									? () => setScreen('newsletter-list')
									: undefined
							}
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

					{screen === 'newsletter-list' ? (
						<NewsletterListForm
							input={newsletterList.input}
							onChange={newsletterList.setInput}
							disabled={newsletterList.isSaving}
						/>
					) : null}

					{screen === 'newsletter-list-done' &&
					newsletterList.created ? (
						<NewsletterListCreated
							name={newsletterList.created.name}
						/>
					) : null}

					{newsletterList.error ? (
						<Notice status="error" isDismissible={false}>
							{newsletterList.error}
						</Notice>
					) : null}
				</div>

				{screen === 'newsletter-list' ||
				screen === 'newsletter-list-done' ? (
					<NewsletterListFooter
						screen={screen}
						canCreate={newsletterList.canCreate}
						isBusy={newsletterList.isSaving}
						onBack={() => {
							newsletterList.clearError();
							setScreen('pick-builder');
						}}
						onCreate={() => {
							void newsletterList.create().then((saved) => {
								if (saved) {
									setScreen('newsletter-list-done');
								}
							});
						}}
						onCreateCampaign={() =>
							void newsletterList.createCampaign()
						}
						onClose={handleClose}
					/>
				) : (
					<BuilderFooter
						screen={screen}
						builder={builder}
						domainStep={domainStep}
						domainInput={domainInput}
						sourceId={sourceId}
						csvInput={csvInput}
						view={view}
						isCreatingDraft={isCreatingDraft}
						onBackDomain={() => setDomainStep(domainStep - 1)}
						onNextDomain={() => setDomainStep(domainStep + 1)}
						onBackToPicker={() => {
							setDomainStep(0);
							setScreen('pick-builder');
						}}
						onStart={handleStart}
						onCreateDraft={() => void createDraft()}
						onClose={handleClose}
					/>
				)}
			</div>
		</Modal>
	);
}

function BuilderFooter({
	screen,
	builder,
	domainStep,
	domainInput,
	sourceId,
	csvInput,
	view,
	isCreatingDraft,
	onBackDomain,
	onNextDomain,
	onBackToPicker,
	onStart,
	onCreateDraft,
	onClose,
}: {
	readonly screen: BuilderScreen;
	readonly builder: AudienceBuilder | null;
	readonly domainStep: number;
	readonly domainInput: StartAudienceInput;
	readonly sourceId: number;
	readonly csvInput: CsvUploadInput;
	readonly view: ReturnType<typeof useAudienceJob>['view'];
	readonly isCreatingDraft: boolean;
	readonly onBackDomain: () => void;
	readonly onNextDomain: () => void;
	readonly onBackToPicker: () => void;
	readonly onStart: () => void;
	readonly onCreateDraft: () => void;
	readonly onClose: () => void;
}) {
	const isDomainForm = screen === 'form' && builder?.form === 'domain-query';
	const showDomainBack = isDomainForm && domainStep > 0;
	const showDomainPickerBack = isDomainForm && domainStep === 0;
	const showDomainNext = isDomainForm && domainStep < 2;
	const showDomainStart = isDomainForm && domainStep === 2;
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
			className="prc-email-audience-builder__footer"
			justify="flex-end"
			gap={2}
		>
			{showDomainPickerBack ? (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={onBackToPicker}
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
				{builderCloseLabel(view?.phase)}
			</Button>
		</Flex>
	);
}
