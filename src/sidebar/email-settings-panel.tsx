import { __ } from '@wordpress/i18n';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { PreviewModal } from './preview/preview-modal';
import {
	useNewsletterMeta,
	isCampaignPostType,
	isTransactionalPostType,
} from './use-newsletter-data';
import { CampaignListControl } from './campaign-mailchimp-settings';
import { InboxSubjectAI } from './inbox-subject-ai';
import { InboxPreviewAI } from './inbox-preview-ai';

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
			title={__('Email Settings', 'prc-email-builder')}
		>
			<div data-prc-tour="email-settings">
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
			</div>
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

	const [isPreviewOpen, setIsPreviewOpen] = useState(false);

	return (
		<PluginDocumentSettingPanel
			name="prc-email-builder-content"
			title={__('Email Content', 'prc-email-builder')}
		>
			<VStack spacing={3}>
				<Button
					style={{
						width: '100%',
						justifyContent: 'center',
					}}
					variant="secondary"
					onClick={() => setIsPreviewOpen(true)}
				>
					{__('Preview', 'prc-email-builder')}
				</Button>

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

export default function EmailSettingsPanel() {
	return (
		<>
			<SettingsPanel />
			<ContentPanel />
		</>
	);
}
