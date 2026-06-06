/**
 * Newsletter Builder sidebar panel.
 *
 * Sections (prc_email_campaign / prc_email_txn posts):
 *  1. Newsletter Settings — delivery channel, subject, preview text,
 *                           Mailchimp audience + segment (campaign)
 *                           or system-audience picker + send status (mandrill txn)
 *                           + template picker (campaign)
 *  2. Email Content      — preview readiness, refresh button, campaign link
 *
 * Section (prc_email_template posts):
 *  - Template Matching   — audience + segment for auto-matching (TemplateSettingsPanel)
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import {
	TextControl,
	SelectControl,
	RadioControl,
	Button,
	Notice,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';

import { PreviewModal } from './preview/preview-modal';
import {
	useNewsletterMeta,
	useAudiences,
	useSegments,
	useSystemAudiences,
	useEmailPreviewStatus,
	isCampaignPostType,
	isTransactionalPostType,
	type PreviewStatus,
} from './use-newsletter-data';
import { InboxSubjectAI } from './inbox-subject-ai';
import { InboxPreviewAI } from './inbox-preview-ai';

// ─── Template picker (newsletter posts, mailchimp mode) ───────────────────────

interface RegisteredTemplate {
	slug: string;
	label: string;
}

interface TemplateSelectorProps {
	templateSlug: string;
	setTemplateSlug: (value: string) => void;
}

function TemplateSelector({
	templateSlug,
	setTemplateSlug,
}: TemplateSelectorProps) {
	const registeredTemplates: RegisteredTemplate[] =
		(window as any).prcEmailBuilderConfig?.templates ?? [];

	const options = [
		{
			value: '',
			label: __(
				'Auto (matched by audience + segment)',
				'prc-email-builder'
			),
		},
		...registeredTemplates.map((t) => ({
			value: t.slug,
			label: t.label,
		})),
	];

	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={__('Email template', 'prc-email-builder')}
			value={templateSlug}
			options={options}
			onChange={setTemplateSlug}
			help={__(
				'Wraps the newsletter with a header and footer. "Auto" uses the template whose audience/segment matches.',
				'prc-email-builder'
			)}
		/>
	);
}

// ─── Mailchimp segment picker ─────────────────────────────────────────────────

interface SegmentPickerProps {
	audienceId: string;
	segmentId: string;
	setSegmentId: (value: string) => void;
}

function SegmentPicker({
	audienceId,
	segmentId,
	setSegmentId,
}: SegmentPickerProps) {
	const { segments, loading, error } = useSegments(audienceId);

	if (error) {
		return (
			<Notice status="warning" isDismissible={false}>
				{error}
			</Notice>
		);
	}

	if (loading) {
		return <Spinner />;
	}

	const options = [
		{
			value: '',
			label: __('Entire audience', 'prc-email-builder'),
		},
		...segments.map((s) => ({
			value: String(s.id),
			label: `${s.name} (${s.member_count.toLocaleString()})`,
		})),
	];

	return (
		<SelectControl
			__nextHasNoMarginBottom
			label={__('Segment (optional)', 'prc-email-builder')}
			value={segmentId}
			options={options}
			onChange={setSegmentId}
			help={__(
				'Restrict delivery to a saved segment of the audience.',
				'prc-email-builder'
			)}
		/>
	);
}

// ─── Sub-panels ──────────────────────────────────────────────────────────────

function SettingsPanel() {
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const {
		postType,
		subject,
		previewText,
		deliveryMode,
		audienceId,
		segmentId,
		audienceOptionKey,
		mandrillSendStatus,
		templateSlug,
		systemEmailKey,
		setSubject,
		setPreviewText,
		setDeliveryMode,
		setAudienceId,
		setSegmentId,
		setAudienceOptionKey,
		setTemplateSlug,
		setSystemEmailKey,
	} = useNewsletterMeta();

	const isCampaign = isCampaignPostType(postType);
	const isTransactional = isTransactionalPostType(postType);

	const {
		audiences,
		loading: audiencesLoading,
		error: audiencesError,
	} = useAudiences();

	const {
		audiences: systemAudiences,
		loading: systemAudiencesLoading,
		error: systemAudiencesError,
	} = useSystemAudiences();

	const audienceOptions = [
		{
			value: '',
			label: __('— Select audience —', 'prc-email-builder'),
		},
		...audiences.map((a) => ({ value: a.id, label: a.name })),
	];

	const systemAudienceOptions = [
		{
			value: '',
			label: __('— Select audience —', 'prc-email-builder'),
		},
		...systemAudiences.map((a) => ({
			value: a.key,
			label: `${a.label} — ${a.count.toLocaleString()} recipients${
				a.built_at ? ` (built ${a.built_at.substring(0, 10)})` : ''
			}`,
		})),
	];

	const sendStatusVariant: Record<
		string,
		'success' | 'warning' | 'error' | 'info'
	> = {
		sent: 'success',
		partial: 'warning',
		failed: 'error',
		sending: 'info',
		queued: 'info',
	};

	return (
		<PluginDocumentSettingPanel
			name="prc-email-builder-settings"
			title={__('Newsletter Settings', 'prc-email-builder')}
		>
			<VStack spacing={3}>
				{isTransactional && (
					<RadioControl
						label={__('Transactional type', 'prc-email-builder')}
						selected={deliveryMode}
						options={[
							{
								value: 'mandrill',
								label: __(
									'Bulk (fixed recipient list)',
									'prc-email-builder'
								),
							},
							{
								value: 'dynamic',
								label: __(
									'Dynamic (per-recipient)',
									'prc-email-builder'
								),
							},
						]}
						onChange={setDeliveryMode}
					/>
				)}

				<InboxSubjectAI
					postId={postId ?? 0}
					subject={subject}
					onChange={setSubject}
					onApply={setSubject}
				/>
				<InboxPreviewAI
					postId={postId ?? 0}
					previewText={previewText}
					currentSubject={subject}
					onChange={setPreviewText}
					onApply={setPreviewText}
				/>

				{isCampaign && (
					<>
						{audiencesError && (
							<Notice status="error" isDismissible={false}>
								{audiencesError}
							</Notice>
						)}
						{audiencesLoading ? (
							<Spinner />
						) : (
							<SelectControl
								__nextHasNoMarginBottom
								label={__(
									'Mailchimp audience',
									'prc-email-builder'
								)}
								value={audienceId}
								options={audienceOptions}
								onChange={setAudienceId}
							/>
						)}
						{audienceId && (
							<SegmentPicker
								audienceId={audienceId}
								segmentId={segmentId}
								setSegmentId={setSegmentId}
							/>
						)}

						<TemplateSelector
							templateSlug={templateSlug}
							setTemplateSlug={setTemplateSlug}
						/>
					</>
				)}

				{isTransactional && deliveryMode === 'mandrill' && (
					<>
						{systemAudiencesError && (
							<Notice status="error" isDismissible={false}>
								{systemAudiencesError}
							</Notice>
						)}
						{systemAudiencesLoading ? (
							<Spinner />
						) : (
							<SelectControl
								__nextHasNoMarginBottom
								label={__(
									'Recipient list',
									'prc-email-builder'
								)}
								value={audienceOptionKey}
								options={systemAudienceOptions}
								onChange={setAudienceOptionKey}
								help={__(
									'Built via `wp prc datasets build-audience`. Use a fresh list before sending.',
									'prc-email-builder'
								)}
							/>
						)}
						{mandrillSendStatus && (
							<Notice
								status={
									sendStatusVariant[mandrillSendStatus] ??
									'info'
								}
								isDismissible={false}
							>
								{sprintf(
									/* translators: %s: send status */
									__('Send status: %s', 'prc-email-builder'),
									mandrillSendStatus
								)}
							</Notice>
						)}
					</>
				)}

				{isTransactional && deliveryMode === 'dynamic' && (
					<>
						<TextControl
							__nextHasNoMarginBottom
							label={__('System email key', 'prc-email-builder')}
							value={systemEmailKey}
							onChange={setSystemEmailKey}
							help={__(
								'Unique slug other plugins/forms use to look up and send this newsletter (e.g. "typology-loyal-liberals").',
								'prc-email-builder'
							)}
						/>
						<Notice status="info" isDismissible={false}>
							{__(
								'Publishing makes this newsletter available as a reusable template. It is sent on demand to a single recipient — no recipient list or campaign is created. Use block bits for per-recipient merge fields.',
								'prc-email-builder'
							)}
						</Notice>
					</>
				)}
			</VStack>
		</PluginDocumentSettingPanel>
	);
}

function ContentPanel() {
	const {
		postType,
		campaignId,
		campaignAdminUrl,
		deliveryMode,
		mandrillSendStatus,
	} = useNewsletterMeta();
	const isCampaign = isCampaignPostType(postType);
	const isTransactional = isTransactionalPostType(postType);

	const mailchimpAdminUrl =
		campaignAdminUrl ||
		(campaignId ? 'https://admin.mailchimp.com/campaigns/' : '');

	const postId: number = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const { status, refresh, isRefreshing } = useEmailPreviewStatus(postId);
	const [isPreviewOpen, setIsPreviewOpen] = useState(false);

	const statusLabel: Record<PreviewStatus, string> = {
		none: __('Not checked', 'prc-email-builder'),
		complete: __('Ready', 'prc-email-builder'),
		error: __('Error', 'prc-email-builder'),
	};

	return (
		<PluginDocumentSettingPanel
			name="prc-email-builder-content"
			title={__('Email Content', 'prc-email-builder')}
		>
			<VStack spacing={3}>
				<Text>
					{sprintf(
						/* translators: %s: status label */
						__('Email preview: %s', 'prc-email-builder'),
						statusLabel[status]
					)}
					{isRefreshing && <Spinner />}
				</Text>

				<Button
					__next40pxDefaultSize
					style={{ width: '100%', justifyContent: 'center' }}
					variant="secondary"
					onClick={refresh}
					isBusy={isRefreshing}
					disabled={isRefreshing}
				>
					{__('Refresh preview', 'prc-email-builder')}
				</Button>

				{status === 'complete' && (
					<Button
						__next40pxDefaultSize
						style={{
							width: '100%',
							justifyContent: 'center',
						}}
						variant="tertiary"
						onClick={() => setIsPreviewOpen(true)}
					>
						{__('Preview', 'prc-email-builder')}
					</Button>
				)}

				{isPreviewOpen && (
					<PreviewModal
						postId={postId}
						onClose={() => setIsPreviewOpen(false)}
					/>
				)}

				{isCampaign && campaignId && (
					<Notice status="success" isDismissible={false}>
						{__('Mailchimp draft created.', 'prc-email-builder')}{' '}
						<a
							href={mailchimpAdminUrl}
							target="_blank"
							rel="noreferrer"
						>
							{__('View in Mailchimp ↗', 'prc-email-builder')}
						</a>
					</Notice>
				)}

				{isTransactional &&
					deliveryMode === 'mandrill' &&
					mandrillSendStatus === 'sent' && (
						<Notice status="success" isDismissible={false}>
							{__(
								'System email delivered via Mandrill.',
								'prc-email-builder'
							)}
						</Notice>
					)}
			</VStack>
		</PluginDocumentSettingPanel>
	);
}

// ─── Root export ─────────────────────────────────────────────────────────────

export default function NewsletterPanel() {
	return (
		<>
			<SettingsPanel />
			<ContentPanel />
		</>
	);
}
