/**
 * Newsletter Builder sidebar panel.
 *
 * Sections (prc_email_campaign / prc_email_txn posts):
 *  1. Newsletter Settings — subject, preview text, and newsletter list picker
 *                           (campaign only). Campaign Mailchimp audience/segment/
 *                           template overrides live in Campaign Setup; transactional
 *                           type, recipient list, and system email key live in
 *                           Transactional Setup (see send/).
 *  2. Email Content      — preview readiness, refresh button, campaign link
 */

import { __, sprintf } from '@wordpress/i18n';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';

import { PreviewModal } from './preview/preview-modal';
import {
	useNewsletterMeta,
	useEmailPreviewStatus,
	isCampaignPostType,
	isTransactionalPostType,
	type PreviewStatus,
} from './use-newsletter-data';
import { CampaignListControl } from './campaign-mailchimp-settings';
import { InboxSubjectAI } from './inbox-subject-ai';
import { InboxPreviewAI } from './inbox-preview-ai';

// ─── Sub-panels ──────────────────────────────────────────────────────────────

function SettingsPanel() {
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const { postType, subject, previewText, setSubject, setPreviewText } =
		useNewsletterMeta();

	const isCampaign = isCampaignPostType(postType);

	return (
		<PluginDocumentSettingPanel
			name="prc-email-builder-settings"
			title={__('Newsletter Settings', 'prc-email-builder')}
		>
			<VStack spacing={3}>
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

				{isCampaign && <CampaignListControl />}
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
