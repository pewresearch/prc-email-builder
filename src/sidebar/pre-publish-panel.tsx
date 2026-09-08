import { Notice, __experimentalVStack as VStack } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { PluginPrePublishPanel, store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import { InboxSubjectAI } from './inbox-subject-ai';
import { EMAIL_SUBJECT_LOCK, parseEmailSubject } from './subject-readiness';
import {
	config,
	isCampaignPostType,
	isEmailPostType,
	useNewsletterMeta,
} from './use-newsletter-data';

function isHeaderPublishButtonTarget(event: Event): boolean {
	const button = event.composedPath().find((node): node is Element => {
		return (
			node instanceof Element &&
			node.classList.contains('editor-post-publish-button__button')
		);
	});
	return !!button && !button.closest('.editor-post-publish-panel');
}

function getBlankSubjectNotice(postType: string | undefined): string {
	if (!isCampaignPostType(postType)) {
		return __(
			'Add a subject line before publishing. Transactional emails cannot be sent without a subject.',
			'prc-email-builder'
		);
	}
	if (config.autoSendOnPublish) {
		return __(
			'Add a subject line before publishing. Campaigns queue a 10-minute Mailchimp send on publish.',
			'prc-email-builder'
		);
	}
	return __(
		'Add a subject line before publishing. Campaigns do not send on publish. Create a Mailchimp draft from Campaign Setup after you publish.',
		'prc-email-builder'
	);
}

export function EmailSubjectPrePublishPanel() {
	const { postType, subject, setSubject } = useNewsletterMeta();
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const { isPublishSidebarEnabled, isPublishSidebarOpened, isPublished } =
		useSelect(
			(select) => ({
				isPublishSidebarEnabled:
					select(editorStore).isPublishSidebarEnabled(),
				isPublishSidebarOpened:
					select(editorStore).isPublishSidebarOpened(),
				isPublished: select(editorStore).isCurrentPostPublished(),
			}),
			[]
		);
	const { openPublishSidebar, lockPostSaving, unlockPostSaving } =
		useDispatch(editorStore);
	const isEmail = isEmailPostType(postType);
	const parsed = parseEmailSubject(subject);

	useEffect(() => {
		if (
			!isEmail ||
			isPublished ||
			isPublishSidebarEnabled ||
			isPublishSidebarOpened ||
			parsed.status !== 'blank'
		) {
			return;
		}

		function handlePublishClick(event: MouseEvent) {
			if (!isHeaderPublishButtonTarget(event)) {
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();
			openPublishSidebar();
		}

		document.addEventListener('click', handlePublishClick, true);
		return () => {
			document.removeEventListener('click', handlePublishClick, true);
		};
	}, [
		isEmail,
		isPublished,
		isPublishSidebarEnabled,
		isPublishSidebarOpened,
		openPublishSidebar,
		parsed.status,
	]);

	useEffect(() => {
		if (isEmail && isPublishSidebarOpened && parsed.status === 'blank') {
			lockPostSaving(EMAIL_SUBJECT_LOCK);
		} else {
			unlockPostSaving(EMAIL_SUBJECT_LOCK);
		}

		return () => {
			unlockPostSaving(EMAIL_SUBJECT_LOCK);
		};
	}, [
		isEmail,
		isPublishSidebarOpened,
		lockPostSaving,
		parsed.status,
		unlockPostSaving,
	]);

	if (!isEmail) {
		return null;
	}

	let statusNotice;
	switch (parsed.status) {
		case 'blank':
			statusNotice = (
				<Notice status="error" isDismissible={false}>
					{getBlankSubjectNotice(postType)}
				</Notice>
			);
			break;
		case 'ready':
			statusNotice = (
				<Notice status="success" isDismissible={false}>
					{__('Subject line is set.', 'prc-email-builder')}
				</Notice>
			);
			break;
		default: {
			const _exhaustive: never = parsed;
			return _exhaustive;
		}
	}

	return (
		<PluginPrePublishPanel
			title={__('Subject line', 'prc-email-builder')}
			initialOpen
		>
			<VStack spacing={3}>
				{statusNotice}
				<InboxSubjectAI
					postId={postId ?? 0}
					subject={subject}
					onChange={setSubject}
					onApply={setSubject}
				/>
			</VStack>
		</PluginPrePublishPanel>
	);
}
