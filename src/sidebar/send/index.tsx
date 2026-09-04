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
import { parseEmailSubject } from '../subject-readiness';
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

interface UnlinkCampaignResponse {
	success: boolean;
	post_id: number;
	cleared: {
		campaign_id: string;
		had_report: boolean;
	};
	linkage: {
		campaign_id: string;
		admin_url: string;
		status: string;
	};
}

interface CreateDraftResponse {
	success: boolean;
	campaign_id: string;
	admin_url: string;
	status: string;
}

export function SendSidebar() {
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
				<div data-prc-tour="email-send">
					<SendPanel postId={postId} />
				</div>
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
	const parsed = parseEmailSubject(subject);
	const subjectReady = parsed.status === 'ready';

	const { audiences: systemAudiences } = useSystemAudiences();
	const { status: transformStatus } = useTransformStatus(postId);
	const [unlinkedThisSession, setUnlinkedThisSession] = useState(false);
	const autoSendOnPublish = config.autoSendOnPublish;
	const shouldPollAutoSendMeta =
		isCampaign &&
		isPublished &&
		transformStatus === 'complete' &&
		!campaignId &&
		!unlinkedThisSession &&
		autoSendOnPublish;
	const { syncTimedOut } = useSyncMailchimpCampaignMeta(
		postId,
		shouldPollAutoSendMeta
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
	const [isUnlinkConfirmOpen, setIsUnlinkConfirmOpen] = useState(false);
	const [isSendConfirmOpen, setIsSendConfirmOpen] = useState(false);
	const [isSending, setIsSending] = useState(false);
	const [isUpdatingDraft, setIsUpdatingDraft] = useState(false);
	const [isUnlinking, setIsUnlinking] = useState(false);
	const [isCreatingDraft, setIsCreatingDraft] = useState(false);

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

	const handleUnlinkMailchimpCampaign = useCallback(async () => {
		if (!postId || isUnlinking) {
			return;
		}

		setIsUnlinking(true);
		setIsUnlinkConfirmOpen(false);

		try {
			await apiFetch<UnlinkCampaignResponse>({
				path: `/${config.restNamespace}/campaigns/unlink`,
				method: 'POST',
				data: { post_id: postId },
			});

			editPost({
				meta: {
					prc_email_mailchimp_campaign_id: '',
					prc_email_mailchimp_campaign_admin_url: '',
					prc_email_mailchimp_campaign_status: '',
				},
			});
			setUnlinkedThisSession(true);
			createSuccessNotice(
				__(
					'Unlinked from Mailchimp. Audience and content settings were kept.',
					'prc-email-builder'
				),
				{ type: 'snackbar' }
			);
		} catch (err: unknown) {
			const message =
				(err as { message?: string })?.message ??
				__(
					'Could not unlink Mailchimp campaign. Try again.',
					'prc-email-builder'
				);
			createErrorNotice(message, { type: 'snackbar' });
		} finally {
			setIsUnlinking(false);
		}
	}, [postId, isUnlinking, editPost, createSuccessNotice, createErrorNotice]);

	const handleSendToMailchimp = useCallback(async () => {
		if (!postId || isCreatingDraft) {
			return;
		}

		setIsCreatingDraft(true);
		setIsSendConfirmOpen(false);

		try {
			const response = await apiFetch<CreateDraftResponse>({
				path: `/${config.restNamespace}/campaigns/create-draft`,
				method: 'POST',
				data: { post_id: postId },
			});

			editPost({
				meta: {
					prc_email_mailchimp_campaign_id: response.campaign_id,
					prc_email_mailchimp_campaign_admin_url: response.admin_url,
					prc_email_mailchimp_campaign_status: response.status,
				},
			});
			setUnlinkedThisSession(false);
			createSuccessNotice(
				response.status === 'sending' || response.status === 'sent'
					? __('Campaign sent to Mailchimp.', 'prc-email-builder')
					: __(
							'Mailchimp campaign created. Send may still be in progress.',
							'prc-email-builder'
						),
				{ type: 'snackbar' }
			);
		} catch (err: unknown) {
			const message =
				(err as { message?: string })?.message ??
				__(
					'Could not send to Mailchimp. Try again.',
					'prc-email-builder'
				);
			createErrorNotice(message, { type: 'snackbar' });
		} finally {
			setIsCreatingDraft(false);
		}
	}, [
		postId,
		isCreatingDraft,
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
		const htmlReady = transformStatus === 'complete';
		const preparing =
			isPublished &&
			htmlReady &&
			!draftReady &&
			!syncTimedOut &&
			!unlinkedThisSession &&
			autoSendOnPublish;
		const showManualSend =
			isPublished &&
			htmlReady &&
			!draftReady &&
			(unlinkedThisSession || syncTimedOut || !autoSendOnPublish);
		const draftEditable = !campaignStatus || campaignStatus === 'save';
		const needsSendRetry = draftReady && draftEditable;
		const unlinkConfirmIsStrong = ['sent', 'schedule', 'sending'].includes(
			campaignStatus
		);
		const mailchimpPrimaryLabel = (() => {
			if (campaignStatus === 'sent') {
				return __('Sent to Mailchimp', 'prc-email-builder');
			}
			if (campaignStatus === 'sending') {
				return __('Sending in Mailchimp…', 'prc-email-builder');
			}
			if (campaignStatus === 'schedule') {
				return __('Scheduled in Mailchimp', 'prc-email-builder');
			}
			return __('Open in Mailchimp', 'prc-email-builder');
		})();

		return (
			<PanelBody
				title={__('Dispatch Info', 'prc-email-builder')}
				initialOpen
			>
				<VStack spacing={3}>
					<CampaignAdvancedSettings />
					<Text>
						{autoSendOnPublish
							? __(
									'Publishing or scheduling this campaign in WordPress creates the Mailchimp campaign and sends it to the configured segment.',
									'prc-email-builder'
								)
							: __(
									'Publishing or scheduling this campaign saves the WordPress post. Send from this panel.',
									'prc-email-builder'
								)}
					</Text>
					{preparing ? (
						<Button
							variant="primary"
							disabled
							style={{ width: '100%', justifyContent: 'center' }}
						>
							{__('Sending to Mailchimp…', 'prc-email-builder')}
							<Spinner />
						</Button>
					) : draftReady ? (
						<>
							{needsSendRetry ? (
								<Button
									variant="primary"
									onClick={() => setIsSendConfirmOpen(true)}
									disabled={isCreatingDraft}
									isBusy={isCreatingDraft}
									style={{
										width: '100%',
										justifyContent: 'center',
									}}
								>
									{__(
										'Send to Mailchimp',
										'prc-email-builder'
									)}
								</Button>
							) : (
								<Button
									variant="primary"
									href={mailchimpUrl}
									target="_blank"
									rel="noreferrer"
									style={{
										width: '100%',
										justifyContent: 'center',
									}}
								>
									{mailchimpPrimaryLabel}
								</Button>
							)}
							{draftEditable && (
								<Button
									variant="secondary"
									onClick={handleUpdateMailchimpDraft}
									disabled={!htmlReady}
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
							)}
							<Button
								variant="tertiary"
								isDestructive
								onClick={() => setIsUnlinkConfirmOpen(true)}
								disabled={isUnlinking}
								isBusy={isUnlinking}
								style={{
									width: '100%',
									justifyContent: 'center',
								}}
							>
								{__(
									'Unlink from Mailchimp',
									'prc-email-builder'
								)}
							</Button>
							{campaignStatus === 'unavailable' && (
								<Notice status="warning" isDismissible={false}>
									{__(
										'The linked Mailchimp campaign is missing. Unlink to send a new campaign.',
										'prc-email-builder'
									)}
								</Notice>
							)}
							{needsSendRetry && (
								<Notice status="warning" isDismissible={false}>
									{__(
										'Mailchimp campaign exists as a draft. Send to deliver it to the segment, or update the draft first.',
										'prc-email-builder'
									)}
								</Notice>
							)}
							{!htmlReady && draftEditable && (
								<Notice status="warning" isDismissible={false}>
									{__(
										'Generate email HTML in Email Content before updating the Mailchimp draft.',
										'prc-email-builder'
									)}
								</Notice>
							)}
							{!draftEditable &&
								campaignStatus !== 'unavailable' && (
									<Notice
										status="warning"
										isDismissible={false}
									>
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
							<ConfirmDialog
								isOpen={isSendConfirmOpen}
								onConfirm={handleSendToMailchimp}
								onCancel={() => setIsSendConfirmOpen(false)}
							>
								{__(
									'Send this campaign to the Mailchimp segment now? This cannot be undone.',
									'prc-email-builder'
								)}
							</ConfirmDialog>
							<ConfirmDialog
								isOpen={isUnlinkConfirmOpen}
								onConfirm={handleUnlinkMailchimpCampaign}
								onCancel={() => setIsUnlinkConfirmOpen(false)}
							>
								{unlinkConfirmIsStrong
									? __(
											'This campaign may already be sent or scheduled in Mailchimp. Unlinking clears the WordPress link only; it does not delete the Mailchimp campaign. Continue?',
											'prc-email-builder'
										)
									: __(
											'Unlink this newsletter from its Mailchimp campaign? Audience and content settings stay. The Mailchimp campaign is not deleted.',
											'prc-email-builder'
										)}
							</ConfirmDialog>
						</>
					) : showManualSend ? (
						<>
							{syncTimedOut &&
								!unlinkedThisSession &&
								autoSendOnPublish && (
									<Notice
										status="error"
										isDismissible={false}
									>
										{__(
											'Mailchimp send did not complete automatically. Send below, or check that Mailchimp is connected.',
											'prc-email-builder'
										)}
									</Notice>
								)}
							{!autoSendOnPublish && (
								<Notice status="info" isDismissible={false}>
									{__(
										'Automatic Mailchimp send on publish is off. Use Send to Mailchimp to deliver this campaign.',
										'prc-email-builder'
									)}
								</Notice>
							)}
							<Button
								variant="primary"
								onClick={() => setIsSendConfirmOpen(true)}
								disabled={isCreatingDraft}
								isBusy={isCreatingDraft}
								style={{
									width: '100%',
									justifyContent: 'center',
								}}
							>
								{__('Send to Mailchimp', 'prc-email-builder')}
							</Button>
							<ConfirmDialog
								isOpen={isSendConfirmOpen}
								onConfirm={handleSendToMailchimp}
								onCancel={() => setIsSendConfirmOpen(false)}
							>
								{__(
									'Send this campaign to the Mailchimp segment now? This cannot be undone.',
									'prc-email-builder'
								)}
							</ConfirmDialog>
						</>
					) : (
						<Notice status="warning" isDismissible={false}>
							{autoSendOnPublish
								? __(
										'Publish or schedule the campaign to send it to Mailchimp.',
										'prc-email-builder'
									)
								: __(
										'Publish the campaign, then send from this panel.',
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
		subjectReady &&
		!isSending &&
		!mandrillSendInProgress &&
		mandrillSendStatus !== 'sent' &&
		recipientCount > 0 &&
		Boolean(audienceOptionKey);

	return (
		<PanelBody title={__('Dispatch Info', 'prc-email-builder')} initialOpen>
			<VStack spacing={3}>
				<TransactionalSettings />
				{subjectReady && (
					<Text>
						<strong>{__('Subject:', 'prc-email-builder')}</strong>{' '}
						{subject}
					</Text>
				)}
				{!subjectReady && (
					<Notice status="warning" isDismissible={false}>
						{__(
							'Add a subject line before sending.',
							'prc-email-builder'
						)}
					</Notice>
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
