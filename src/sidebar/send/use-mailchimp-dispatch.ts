import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';

import {
	config,
	useNewsletterMeta,
	useSegments,
	useSyncMailchimpCampaignMeta,
	useTransformStatus,
} from '../use-newsletter-data';
import {
	getCampaignDispatchMode,
	type CampaignDispatchMode,
} from '../campaign-dispatch-mode';
import {
	useDelayedMailchimpSend,
	type MailchimpLinkageResponse,
} from './use-delayed-mailchimp-send';

const MAILCHIMP_FALLBACK_URL = 'https://admin.mailchimp.com/campaigns/';

interface UnlinkCampaignResponse {
	success: boolean;
}

function useMailchimpRecipientCount(
	audienceId: string,
	segmentId: string
): number {
	const { segments } = useSegments(audienceId);
	const [audienceCount, setAudienceCount] = useState(0);

	useEffect(() => {
		if (!audienceId || segmentId) {
			setAudienceCount(0);
			return;
		}

		let cancelled = false;
		apiFetch<{ count?: number }>({
			path: `/${config.restNamespace}/audiences/${encodeURIComponent(
				audienceId
			)}/count`,
		})
			.then((data) => {
				if (!cancelled) {
					setAudienceCount(data.count ?? 0);
				}
			})
			.catch(() => {
				if (!cancelled) {
					setAudienceCount(0);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [audienceId, segmentId]);

	if (segmentId) {
		const segment = segments.find(
			(item) => String(item.id) === String(segmentId)
		);
		return segment?.member_count ?? 0;
	}

	return audienceCount;
}

export interface MailchimpDispatchState {
	autoSendOnPublish: boolean;
	delayedSendSeconds: number;
	htmlReady: boolean;
	campaignStatus: string;
	pendingSendAt: number;
	mailchimpUrl: string;
	dispatchMode: CampaignDispatchMode;
	syncTimedOut: boolean;
	unlinkedThisSession: boolean;
	isCreatingDraft: boolean;
	isUpdatingDraft: boolean;
	isUnlinking: boolean;
	isSendingMailchimp: boolean;
	isCancellingDelayedSend: boolean;
	isSendingImmediately: boolean;
	isSendConfirmOpen: boolean;
	isUnlinkConfirmOpen: boolean;
	mailchimpRecipientCount: number;
	setIsSendConfirmOpen: (open: boolean) => void;
	setIsUnlinkConfirmOpen: (open: boolean) => void;
	handleCreateMailchimpDraft: () => Promise<void>;
	handleSendNow: () => Promise<void>;
	handleCancelDelayedSend: () => Promise<void>;
	handleSendImmediately: () => Promise<void>;
	handleUpdateMailchimpDraft: () => Promise<void>;
	handleUnlinkMailchimpCampaign: () => Promise<void>;
}

export function useMailchimpDispatch(postId: number): MailchimpDispatchState {
	const isPublished = useSelect(
		(select) =>
			select(editorStore).isCurrentPostPublished?.() ??
			select(editorStore).getCurrentPostAttribute('status') === 'publish',
		[]
	);
	const {
		audienceId,
		segmentId,
		campaignId,
		campaignAdminUrl,
		campaignStatus,
		pendingSendAt,
		cancelledDelayedSend,
	} = useNewsletterMeta();
	const { status: transformStatus } = useTransformStatus(postId);
	const [unlinkedThisSession, setUnlinkedThisSession] = useState(false);
	const [
		cancelledDelayedSendThisSession,
		setCancelledDelayedSendThisSession,
	] = useState(false);
	const autoSendOnPublish = config.autoSendOnPublish;
	const htmlReady = transformStatus === 'complete';
	const isTerminalStatus =
		campaignStatus === 'sending' || campaignStatus === 'sent';
	const shouldPollAutoSendMeta =
		isPublished &&
		htmlReady &&
		!unlinkedThisSession &&
		!isTerminalStatus &&
		(pendingSendAt > 0 ||
			(autoSendOnPublish &&
				!campaignId &&
				!cancelledDelayedSend &&
				!cancelledDelayedSendThisSession));
	const { syncTimedOut } = useSyncMailchimpCampaignMeta(
		postId,
		shouldPollAutoSendMeta
	);
	const { editPost } = useDispatch(editorStore);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);
	const [isUnlinkConfirmOpen, setIsUnlinkConfirmOpen] = useState(false);
	const [isSendConfirmOpen, setIsSendConfirmOpen] = useState(false);
	const [isUpdatingDraft, setIsUpdatingDraft] = useState(false);
	const [isUnlinking, setIsUnlinking] = useState(false);
	const [isCreatingDraft, setIsCreatingDraft] = useState(false);
	const delayedSend = useDelayedMailchimpSend(postId);
	const {
		persistMailchimpLinkage,
		handleQueueSend,
		handleCancelDelayedSend,
		handleSendImmediately,
		isSendingMailchimp,
		isCancellingDelayedSend,
		isSendingImmediately,
	} = delayedSend;
	const mailchimpRecipientCount = useMailchimpRecipientCount(
		audienceId,
		segmentId
	);
	const mailchimpUrl =
		campaignAdminUrl || (campaignId ? MAILCHIMP_FALLBACK_URL : '');
	const dispatchMode = getCampaignDispatchMode({
		isPublished,
		htmlReady,
		campaignId,
		campaignStatus,
		autoSendOnPublish,
		unlinkedThisSession,
		syncTimedOut,
		pendingSendAt,
		cancelledDelayedSendThisSession:
			cancelledDelayedSend || cancelledDelayedSendThisSession,
	});

	const handleCreateMailchimpDraft = useCallback(async () => {
		if (!postId || isCreatingDraft) {
			return;
		}
		setIsCreatingDraft(true);
		try {
			const response = await apiFetch<MailchimpLinkageResponse>({
				path: `/${config.restNamespace}/campaigns/create-draft`,
				method: 'POST',
				data: { post_id: postId },
			});
			persistMailchimpLinkage(response);
			setUnlinkedThisSession(false);
			createSuccessNotice(
				__('Mailchimp draft created.', 'prc-email-builder'),
				{ type: 'snackbar' }
			);
		} catch (err: unknown) {
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not create Mailchimp draft. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsCreatingDraft(false);
		}
	}, [
		postId,
		isCreatingDraft,
		persistMailchimpLinkage,
		createSuccessNotice,
		createErrorNotice,
	]);

	const handleSendNow = useCallback(async () => {
		setIsSendConfirmOpen(false);
		setCancelledDelayedSendThisSession(false);
		await handleQueueSend();
	}, [handleQueueSend]);

	const handleCancelDelayedSendAndRemember = useCallback(async () => {
		await handleCancelDelayedSend();
		setCancelledDelayedSendThisSession(true);
	}, [handleCancelDelayedSend]);

	const handleUpdateMailchimpDraft = useCallback(async () => {
		if (!postId || isUpdatingDraft) {
			return;
		}
		setIsUpdatingDraft(true);
		try {
			const response = await apiFetch<MailchimpLinkageResponse>({
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
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not update Mailchimp draft. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
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
					prc_email_mailchimp_pending_send_at: '',
					prc_email_mailchimp_delayed_send_cancelled: '',
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
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not unlink Mailchimp campaign. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsUnlinking(false);
		}
	}, [postId, isUnlinking, editPost, createSuccessNotice, createErrorNotice]);

	return {
		autoSendOnPublish,
		delayedSendSeconds: config.delayedSendSeconds,
		htmlReady,
		campaignStatus,
		pendingSendAt,
		mailchimpUrl,
		dispatchMode,
		syncTimedOut,
		unlinkedThisSession,
		isCreatingDraft,
		isUpdatingDraft,
		isUnlinking,
		isSendingMailchimp,
		isCancellingDelayedSend,
		isSendingImmediately,
		isSendConfirmOpen,
		isUnlinkConfirmOpen,
		mailchimpRecipientCount,
		setIsSendConfirmOpen,
		setIsUnlinkConfirmOpen,
		handleCreateMailchimpDraft,
		handleSendNow,
		handleCancelDelayedSend: handleCancelDelayedSendAndRemember,
		handleSendImmediately,
		handleUpdateMailchimpDraft,
		handleUnlinkMailchimpCampaign,
	};
}
