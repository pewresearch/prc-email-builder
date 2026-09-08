import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';

import { config } from '../use-newsletter-data';
import { delayMinutesFromSeconds } from './delayed-send-status';

export interface MailchimpLinkageResponse {
	success: boolean;
	campaign_id: string;
	admin_url: string;
	status: string;
}

interface DelayedSendResponse {
	success: boolean;
	queued?: boolean;
	cancelled?: boolean;
	pending_send_at?: number;
	campaign_id?: string;
	admin_url?: string;
	status?: string;
}

export function useDelayedMailchimpSend(postId: number) {
	const { editPost } = useDispatch(editorStore);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);
	const [isSendingMailchimp, setIsSendingMailchimp] = useState(false);
	const [isCancellingDelayedSend, setIsCancellingDelayedSend] =
		useState(false);
	const [isSendingImmediately, setIsSendingImmediately] = useState(false);
	const delayMinutes = delayMinutesFromSeconds(config.delayedSendSeconds);

	const persistPendingSendAt = useCallback(
		(timestamp: number, delayedSendCancelled = false) => {
			editPost({
				meta: {
					prc_email_mailchimp_pending_send_at:
						timestamp > 0 ? String(timestamp) : '',
					prc_email_mailchimp_delayed_send_cancelled:
						delayedSendCancelled ? '1' : '',
				},
			});
		},
		[editPost]
	);

	const persistMailchimpLinkage = useCallback(
		(response: MailchimpLinkageResponse) => {
			editPost({
				meta: {
					prc_email_mailchimp_campaign_id: response.campaign_id,
					prc_email_mailchimp_campaign_admin_url: response.admin_url,
					prc_email_mailchimp_campaign_status: response.status,
					prc_email_mailchimp_pending_send_at: '',
					prc_email_mailchimp_delayed_send_cancelled: '',
				},
			});
		},
		[editPost]
	);

	const handleQueueSend = useCallback(async () => {
		if (!postId || isSendingMailchimp) {
			return;
		}
		setIsSendingMailchimp(true);
		try {
			const response = await apiFetch<DelayedSendResponse>({
				path: `/${config.restNamespace}/campaigns/send`,
				method: 'POST',
				data: { post_id: postId },
			});
			const pendingSendAt = response.pending_send_at ?? 0;
			if (pendingSendAt > 0) {
				persistPendingSendAt(pendingSendAt);
				createSuccessNotice(
					sprintf(
						/* translators: %s: delay in minutes */
						__(
							'Mailchimp send queued. You have %s minutes to cancel.',
							'prc-email-builder'
						),
						delayMinutes
					),
					{ type: 'snackbar' }
				);
				return;
			}
			if (response.campaign_id && response.status) {
				persistMailchimpLinkage({
					success: true,
					campaign_id: response.campaign_id,
					admin_url: response.admin_url ?? '',
					status: response.status,
				});
			}
		} catch (err: unknown) {
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not queue Mailchimp send. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsSendingMailchimp(false);
		}
	}, [
		postId,
		isSendingMailchimp,
		persistPendingSendAt,
		persistMailchimpLinkage,
		createSuccessNotice,
		createErrorNotice,
		delayMinutes,
	]);

	const handleCancelDelayedSend = useCallback(async () => {
		if (!postId || isCancellingDelayedSend) {
			return;
		}
		setIsCancellingDelayedSend(true);
		try {
			await apiFetch<DelayedSendResponse>({
				path: `/${config.restNamespace}/campaigns/cancel-delayed-send`,
				method: 'POST',
				data: { post_id: postId },
			});
			persistPendingSendAt(0, true);
			createSuccessNotice(
				__('Mailchimp send cancelled.', 'prc-email-builder'),
				{ type: 'snackbar' }
			);
		} catch (err: unknown) {
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not cancel Mailchimp send. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsCancellingDelayedSend(false);
		}
	}, [
		postId,
		isCancellingDelayedSend,
		persistPendingSendAt,
		createSuccessNotice,
		createErrorNotice,
	]);

	const handleSendImmediately = useCallback(async () => {
		if (!postId || isSendingImmediately) {
			return;
		}
		setIsSendingImmediately(true);
		try {
			const response = await apiFetch<MailchimpLinkageResponse>({
				path: `/${config.restNamespace}/campaigns/send`,
				method: 'POST',
				data: { post_id: postId, immediate: true },
			});
			persistMailchimpLinkage(response);
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
			createErrorNotice(
				(err as { message?: string })?.message ??
					__(
						'Could not send to Mailchimp. Try again.',
						'prc-email-builder'
					),
				{ type: 'snackbar' }
			);
		} finally {
			setIsSendingImmediately(false);
		}
	}, [
		postId,
		isSendingImmediately,
		persistMailchimpLinkage,
		createSuccessNotice,
		createErrorNotice,
	]);

	return {
		isSendingMailchimp,
		isCancellingDelayedSend,
		isSendingImmediately,
		persistMailchimpLinkage,
		handleQueueSend,
		handleCancelDelayedSend,
		handleSendImmediately,
	};
}
