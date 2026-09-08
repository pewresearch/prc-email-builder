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
	__experimentalVStack as VStack,
	__experimentalText as Text,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { send } from '@wordpress/icons';

import {
	config,
	useNewsletterMeta,
	useSystemAudiences,
	useSyncMandrillSendMeta,
	useTransformStatus,
	isEmailPostType,
	isCampaignPostType,
	isTransactionalPostType,
} from '../use-newsletter-data';
import { AutomationsSettings } from '../automations';
import { parseEmailSubject } from '../subject-readiness';
import { TransactionalSettings } from '../transactional-settings';
import { MailchimpDispatchPanel } from './mailchimp-dispatch';

const PLUGIN_NAME = 'prc-email-builder';
const SIDEBAR_NAME = `${PLUGIN_NAME}/send`;

interface SendResponse {
	status: string;
	summary: Record<string, number | string>;
}

interface SendPanelProps {
	postId: number;
}

function getMandrillNoticeStatus(
	status: string
): 'success' | 'warning' | 'error' | 'info' {
	if (status === 'sent') {
		return 'success';
	}
	if (status === 'partial') {
		return 'warning';
	}
	if (status === 'failed') {
		return 'error';
	}
	return 'info';
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

function SendPanel({ postId }: SendPanelProps) {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);

	if (isCampaignPostType(postType)) {
		return <MailchimpDispatchPanel postId={postId} />;
	}

	return <TransactionalSendPanel postId={postId} />;
}

function TransactionalSendPanel({ postId }: SendPanelProps) {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const {
		subject,
		deliveryMode,
		audienceOptionKey,
		mandrillSendStatus,
		mandrillSendSummary,
	} = useNewsletterMeta();
	const isTransactional = isTransactionalPostType(postType);
	const parsed = parseEmailSubject(subject);
	const subjectReady = parsed.status === 'ready';
	const { audiences: systemAudiences } = useSystemAudiences();
	const { status: transformStatus } = useTransformStatus(postId);
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
	const selectedAudience = systemAudiences.find(
		(a) => a.key === audienceOptionKey
	);
	const recipientCount = selectedAudience?.count ?? 0;

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
						status={getMandrillNoticeStatus(mandrillSendStatus)}
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
