/**
 * Campaign Setup / Transactional Setup — pinned editor toolbar sidebar.
 *
 * SendPanel renders collapsible PanelBody sections:
 *  - Dispatch Info — delivery settings and send/draft actions (all email types).
 *  - Automations — follow-up sequence config (dynamic transactional only).
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	PanelBody,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalText as Text,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { send } from '@wordpress/icons';

import {
	config,
	useNewsletterMeta,
	useSystemAudiences,
	useSyncMailchimpCampaignMeta,
	useSyncMandrillSendMeta,
	useTransformStatus,
	isEmailPostType,
	isCampaignPostType,
	isTransactionalPostType,
} from '../use-newsletter-data';
import { AutomationsSettings } from '../automations';
import { CampaignAdvancedSettings } from '../campaign-mailchimp-settings';
import { TransactionalSettings } from '../transactional-settings';

const PLUGIN_NAME = 'prc-email-builder';
const SIDEBAR_NAME = `${PLUGIN_NAME}/send`;

const MAILCHIMP_FALLBACK_URL = 'https://admin.mailchimp.com/campaigns/';

interface SendResponse {
	status: string;
	summary: Record<string, number | string>;
}

interface UpdateDraftResponse {
	success: boolean;
	campaign_id: string;
	admin_url: string;
	status: string;
}

export function SendNewsletterSidebar() {
	const postId: number = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);

	if (!isEmailPostType(postType)) {
		return null;
	}

	const title = isCampaignPostType(postType)
		? __('Campaign Setup', 'prc-email-builder')
		: __('Transactional Setup', 'prc-email-builder');

	return (
		<>
			<PluginSidebarMoreMenuItem target={SIDEBAR_NAME} icon={send}>
				{title}
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={SIDEBAR_NAME} title={title} icon={send}>
				<SendPanel postId={postId} />
			</PluginSidebar>
		</>
	);
}

interface SendPanelProps {
	postId: number;
}

function SendPanel({ postId }: SendPanelProps) {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);

	const isPublished = useSelect(
		(select) =>
			select(editorStore).isCurrentPostPublished?.() ??
			select(editorStore).getCurrentPostAttribute('status') === 'publish',
		[]
	);

	const {
		subject,
		deliveryMode,
		audienceOptionKey,
		mandrillSendStatus,
		mandrillSendSummary,
		campaignId,
		campaignAdminUrl,
		campaignStatus,
	} = useNewsletterMeta();

	const isCampaign = isCampaignPostType(postType);
	const isTransactional = isTransactionalPostType(postType);

	const { audiences: systemAudiences } = useSystemAudiences();
	const { status: transformStatus } = useTransformStatus(postId);
	const shouldSyncMailchimpCampaign =
		isCampaign &&
		isPublished &&
		transformStatus === 'complete' &&
		!campaignId;
	const { syncTimedOut } = useSyncMailchimpCampaignMeta(
		postId,
		shouldSyncMailchimpCampaign
	);
	const shouldSyncMandrillSend =
		isTransactional &&
		deliveryMode === 'mandrill' &&
		mandrillSendStatus === 'sending';
	useSyncMandrillSendMeta(postId, shouldSyncMandrillSend);
	const { editPost } = useDispatch(editorStore);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);

	const [isConfirmOpen, setIsConfirmOpen] = useState(false);
	const [isSending, setIsSending] = useState(false);
	const [isUpdatingDraft, setIsUpdatingDraft] = useState(false);

	const selectedAudience = systemAudiences.find(
		(a) => a.key === audienceOptionKey
	);
	const recipientCount = selectedAudience?.count ?? 0;

	const mailchimpUrl =
		campaignAdminUrl || (campaignId ? MAILCHIMP_FALLBACK_URL : '');

	const handleMandrillSend = useCallback(async () => {
		if (!postId || isSending) {
			return;
		}

		setIsSending(true);
		setIsConfirmOpen(false);

		try {
			const response = await apiFetch<SendResponse>({
				path: `/${config.restNamespace}/send`,
				method: 'POST',
				data: { post_id: postId },
			});

			editPost({
				meta: {
					prc_email_mandrill_send_status: response.status,
					prc_email_mandrill_send_summary: JSON.stringify(
						response.summary
					),
				},
			});
			if (response.status === 'sending' || response.status === 'sent') {
				createSuccessNotice(
					response.status === 'sending'
						? __(
								'Newsletter queued for delivery via Mandrill.',
								'prc-email-builder'
							)
						: __(
								'Newsletter sent via Mandrill.',
								'prc-email-builder'
							),
					{ type: 'snackbar' }
				);
			} else if (response.status === 'partial') {
				createErrorNotice(
					__(
						'Newsletter partially sent. Check send status and retry if needed.',
						'prc-email-builder'
					),
					{ type: 'snackbar' }
				);
			} else {
				createErrorNotice(
					sprintf(
						/* translators: %s: send status */
						__(
							'Send finished with status: %s',
							'prc-email-builder'
						),
						response.status
					),
					{ type: 'snackbar' }
				);
			}
		} catch (err: unknown) {
			const message =
				(err as { message?: string })?.message ??
				__(
					'Send failed. Try again or use WP-CLI.',
					'prc-email-builder'
				);
			createErrorNotice(message, { type: 'snackbar' });
		} finally {
			setIsSending(false);
		}
	}, [postId, isSending, editPost, createSuccessNotice, createErrorNotice]);

	const handleUpdateMailchimpDraft = useCallback(async () => {
		if (!postId || isUpdatingDraft) {
			return;
		}

		setIsUpdatingDraft(true);

		try {
			const response = await apiFetch<UpdateDraftResponse>({
				path: `/${config.restNamespace}/campaigns/update-draft`,
				method: 'POST',
				data: { post_id: postId },
			});

			editPost({
				meta: {
					prc_email_mailchimp_campaign_status: response.status,
				},
			});
			createSuccessNotice(
				__(
					'Mailchimp draft updated with current content and settings.',
					'prc-email-builder'
				),
				{ type: 'snackbar' }
			);
		} catch (err: unknown) {
			const message =
				(err as { message?: string })?.message ??
				__(
					'Could not update Mailchimp draft. Try again.',
					'prc-email-builder'
				);
			createErrorNotice(message, { type: 'snackbar' });
		} finally {
			setIsUpdatingDraft(false);
		}
	}, [
		postId,
		isUpdatingDraft,
		editPost,
		createSuccessNotice,
		createErrorNotice,
	]);

	if (isTransactional && deliveryMode === 'dynamic') {
		return (
			<>
				<PanelBody
					title={__('Dispatch Info', 'prc-email-builder')}
					initialOpen
				>
					<VStack spacing={3}>
						<TransactionalSettings />
						<Notice status="info" isDismissible={false}>
							{__(
								'Dynamic newsletters are sent on demand by other systems, not from this panel.',
								'prc-email-builder'
							)}
						</Notice>
					</VStack>
				</PanelBody>
				<PanelBody
					title={__('Automations', 'prc-email-builder')}
					initialOpen
				>
					<AutomationsSettings />
				</PanelBody>
			</>
		);
	}

	if (isCampaign) {
		const draftReady = Boolean(campaignId);
		const preparing =
			isPublished &&
			!draftReady &&
			!syncTimedOut &&
			transformStatus === 'complete' &&
			!campaignId;
		const draftEditable = !campaignStatus || campaignStatus === 'save';
		const htmlReady = transformStatus === 'complete';

		return (
			<PanelBody
				title={__('Dispatch Info', 'prc-email-builder')}
				initialOpen
			>
				<VStack spacing={3}>
					<CampaignAdvancedSettings />
					<Text>
						{__(
							'Mailchimp sends happen in the Mailchimp app. This newsletter creates a draft campaign when email HTML is ready.',
							'prc-email-builder'
						)}
					</Text>
					{preparing ? (
						<Button
							__next40pxDefaultSize
							variant="primary"
							disabled
							style={{ width: '100%', justifyContent: 'center' }}
						>
							{__(
								'Preparing Mailchimp draft…',
								'prc-email-builder'
							)}
							<Spinner />
						</Button>
					) : draftReady ? (
						<>
							<Button
								__next40pxDefaultSize
								variant="primary"
								href={mailchimpUrl}
								target="_blank"
								rel="noreferrer"
								style={{
									width: '100%',
									justifyContent: 'center',
								}}
							>
								{__(
									'Draft sent to Mailchimp',
									'prc-email-builder'
								)}
							</Button>
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={handleUpdateMailchimpDraft}
								disabled={!draftEditable || !htmlReady}
								isBusy={isUpdatingDraft}
								style={{
									width: '100%',
									justifyContent: 'center',
								}}
							>
								{__(
									'Update Mailchimp draft',
									'prc-email-builder'
								)}
							</Button>
							{!htmlReady && (
								<Notice status="warning" isDismissible={false}>
									{__(
										'Generate email HTML in Email Content before updating the Mailchimp draft.',
										'prc-email-builder'
									)}
								</Notice>
							)}
							{!draftEditable && (
								<Notice status="warning" isDismissible={false}>
									{campaignStatus === 'sent'
										? __(
												'Campaign already sent in Mailchimp; content can no longer be updated.',
												'prc-email-builder'
											)
										: campaignStatus === 'schedule'
											? __(
													'Campaign is scheduled in Mailchimp. Unschedule in Mailchimp to edit content here.',
													'prc-email-builder'
												)
											: __(
													'Campaign is no longer a draft in Mailchimp; content can no longer be updated.',
													'prc-email-builder'
												)}
								</Notice>
							)}
						</>
					) : syncTimedOut ? (
						<Notice status="error" isDismissible={false}>
							{__(
								'Mailchimp draft was not created. Check that Mailchimp is connected and republish the campaign.',
								'prc-email-builder'
							)}
						</Notice>
					) : (
						<Notice status="warning" isDismissible={false}>
							{__(
								'Publish the campaign and wait for the Mailchimp draft to be created.',
								'prc-email-builder'
							)}
						</Notice>
					)}
				</VStack>
			</PanelBody>
		);
	}

	if (!isTransactional || deliveryMode !== 'mandrill') {
		return null;
	}

	const htmlReady = transformStatus === 'complete';
	const mandrillSendInProgress = mandrillSendStatus === 'sending';
	const canSend =
		htmlReady &&
		!isSending &&
		!mandrillSendInProgress &&
		mandrillSendStatus !== 'sent' &&
		recipientCount > 0 &&
		Boolean(audienceOptionKey);

	return (
		<PanelBody title={__('Dispatch Info', 'prc-email-builder')} initialOpen>
			<VStack spacing={3}>
				<TransactionalSettings />
				{subject && (
					<Text>
						<strong>{__('Subject:', 'prc-email-builder')}</strong>{' '}
						{subject}
					</Text>
				)}
				{selectedAudience && (
					<Text>
						{sprintf(
							/* translators: 1: audience label, 2: recipient count */
							__(
								'Audience: %1$s (%2$s recipients)',
								'prc-email-builder'
							),
							selectedAudience.label,
							recipientCount.toLocaleString()
						)}
					</Text>
				)}
				{!audienceOptionKey && (
					<Notice status="warning" isDismissible={false}>
						{__(
							'Select a recipient list above before sending.',
							'prc-email-builder'
						)}
					</Notice>
				)}
				{!htmlReady && (
					<Notice status="warning" isDismissible={false}>
						{__(
							'Generate email HTML in Email Content before sending.',
							'prc-email-builder'
						)}
					</Notice>
				)}
				{mandrillSendStatus && (
					<Notice
						status={
							mandrillSendStatus === 'sent'
								? 'success'
								: mandrillSendStatus === 'partial'
									? 'warning'
									: mandrillSendStatus === 'failed'
										? 'error'
										: 'info'
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
				{mandrillSendSummary && (
					<Text variant="muted">
						{sprintf(
							/* translators: 1: queued count, 2: rejected count */
							__(
								'Queued: %1$s · Rejected: %2$s',
								'prc-email-builder'
							),
							String(mandrillSendSummary.queued ?? 0),
							String(mandrillSendSummary.rejected ?? 0)
						)}
					</Text>
				)}
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={() => setIsConfirmOpen(true)}
					disabled={!canSend}
					isBusy={isSending || mandrillSendInProgress}
					style={{ width: '100%', justifyContent: 'center' }}
				>
					{__('Send', 'prc-email-builder')}
				</Button>
				<Text variant="muted">
					{__(
						'Accepted by Mandrill does not guarantee inbox delivery. Confirm delivery in the Mandrill Outbound Activity dashboard.',
						'prc-email-builder'
					)}
				</Text>
				<ConfirmDialog
					isOpen={isConfirmOpen}
					onConfirm={handleMandrillSend}
					onCancel={() => setIsConfirmOpen(false)}
				>
					{sprintf(
						/* translators: %s: recipient count */
						__(
							'Send to %s recipients? This cannot be undone.',
							'prc-email-builder'
						),
						recipientCount.toLocaleString()
					)}
				</ConfirmDialog>
			</VStack>
		</PanelBody>
	);
}
