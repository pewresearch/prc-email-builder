import { useState, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

const MAILCHIMP_SYNC_POLL_MS = 3000;
const MAILCHIMP_QUEUE_MAX_ATTEMPTS = 20;
const MAILCHIMP_PENDING_MAX_ATTEMPTS = 240;

function parsePendingSendAt(value: unknown): number {
	const parsed =
		typeof value === 'number' ? value : parseInt(String(value ?? ''), 10);
	return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

/**
 * Poll server post meta for a queued or in-flight Mailchimp send.
 *
 * After publish with auto-send on, success is `pending_send_at` (the
 * 10-minute Action Scheduler window). Keep polling while that timestamp
 * is set until status is sending/sent or the queue is cancelled. Do not
 * apply a draft (`save` / empty) snapshot into the editor until send
 * leaves that state.
 *
 * @param postId     Newsletter post ID.
 * @param shouldSync When true, poll until send meta is settled in the editor.
 */
export function useSyncMailchimpCampaignMeta(
	postId: number,
	shouldSync: boolean
): { syncTimedOut: boolean } {
	const [syncTimedOut, setSyncTimedOut] = useState(false);
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const { editPost } = useDispatch(editorStore);

	useEffect(() => {
		if (!shouldSync || !postId || !postType) {
			// Keep any prior timeout so unpublishing a timed-out campaign does
			// not hide the Mailchimp failure notice. It resets when a new poll
			// cycle starts below (i.e. on republish).
			return undefined;
		}

		let cancelled = false;
		let attempts = 0;
		let timer: ReturnType<typeof setInterval> | undefined;
		let sawPending = false;

		const stopPolling = () => {
			if (timer) {
				clearInterval(timer);
				timer = undefined;
			}
		};

		const applyCampaignMeta = (draft: {
			id: string;
			status: string;
			adminUrl: string;
			pendingSendAt: number;
		}) => {
			editPost({
				meta: {
					prc_email_mailchimp_campaign_id: draft.id,
					prc_email_mailchimp_campaign_status: draft.status,
					prc_email_mailchimp_pending_send_at:
						draft.pendingSendAt > 0
							? String(draft.pendingSendAt)
							: '',
					...(draft.adminUrl
						? {
								prc_email_mailchimp_campaign_admin_url:
									draft.adminUrl,
							}
						: {}),
				},
			});
		};

		const sync = () => {
			if (cancelled) {
				return;
			}
			attempts += 1;
			const maxAttempts = sawPending
				? MAILCHIMP_PENDING_MAX_ATTEMPTS
				: MAILCHIMP_QUEUE_MAX_ATTEMPTS;
			const isFinalAttempt = attempts > maxAttempts;
			if (isFinalAttempt) {
				stopPolling();
			}

			apiFetch<{ meta?: Record<string, string> }>({
				path: `/wp/v2/${postType}/${postId}?context=edit&_fields=meta`,
			})
				.then((data) => {
					if (cancelled) {
						return;
					}
					const pendingSendAt = parsePendingSendAt(
						data.meta?.prc_email_mailchimp_pending_send_at
					);
					const id = data.meta?.prc_email_mailchimp_campaign_id ?? '';
					const status =
						data.meta?.prc_email_mailchimp_campaign_status ?? '';
					const adminUrl =
						data.meta?.prc_email_mailchimp_campaign_admin_url ?? '';
					const isSending = status === 'sending' || status === 'sent';

					if (pendingSendAt > 0) {
						sawPending = true;
						applyCampaignMeta({
							id,
							status,
							adminUrl,
							pendingSendAt,
						});
						if (
							isFinalAttempt &&
							attempts > MAILCHIMP_PENDING_MAX_ATTEMPTS
						) {
							setSyncTimedOut(true);
						}
						return;
					}

					if (isSending && id) {
						stopPolling();
						applyCampaignMeta({
							id,
							status,
							adminUrl,
							pendingSendAt: 0,
						});
						return;
					}

					if (sawPending) {
						applyCampaignMeta({
							id,
							status,
							adminUrl,
							pendingSendAt: 0,
						});
						stopPolling();
						if (!isSending) {
							setSyncTimedOut(true);
						}
						return;
					}

					if (!id || !status || status === 'save') {
						if (isFinalAttempt) {
							setSyncTimedOut(true);
						}
						return;
					}
					stopPolling();
					applyCampaignMeta({
						id,
						status,
						adminUrl,
						pendingSendAt: 0,
					});
				})
				.catch(() => {
					if (cancelled) {
						return;
					}
					if (isFinalAttempt) {
						setSyncTimedOut(true);
					}
				});
		};

		setSyncTimedOut(false);
		sync();
		timer = setInterval(sync, MAILCHIMP_SYNC_POLL_MS);
		return () => {
			cancelled = true;
			stopPolling();
		};
	}, [postId, postType, shouldSync, editPost]);

	return { syncTimedOut };
}
