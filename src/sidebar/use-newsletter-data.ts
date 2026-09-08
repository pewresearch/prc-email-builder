/**
 * Data-fetching hooks shared between newsletter and template sidebar panels.
 */

import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export interface Audience {
	id: string;
	name: string;
}

export interface Segment {
	id: number;
	name: string;
	type: 'saved' | 'static';
	member_count: number;
}

export interface SystemAudience {
	key: string;
	label: string;
	count: number;
	dataset_id: number | null;
	built_at: string | null;
}

export type PreviewStatus = 'none' | 'complete' | 'error';
export type TransactionalDeliveryMode = 'mandrill' | 'dynamic';

export const CAMPAIGN_POST_TYPE = 'prc_email_campaign';
export const TRANSACTIONAL_POST_TYPE = 'prc_email_txn';
export const NEWSLETTER_LIST_TAXONOMY = 'prc_newsletter_list';

export interface NewsletterListTerm {
	id: number;
	name: string;
	meta?: {
		prc_newsletter_list_audience_id?: string;
		prc_newsletter_list_segment_id?: string;
		prc_newsletter_list_from_name?: string;
		prc_newsletter_list_from_email?: string;
		prc_newsletter_list_campaign_pattern?: string;
	};
}

const localizedConfig = (window as any).prcEmailBuilderConfig ?? {};

export const config: {
	restNamespace: string;
	postTypes: string[];
	campaignPostType: string;
	transactionalPostType: string;
	campaignPatternCategorySlug?: string;
	transactionalPatternCategorySlug?: string;
	nonce: string;
	autoSendOnPublish: boolean;
	delayedSendSeconds: number;
	defaults?: {
		from_name: string;
		from_email: string;
	};
} = {
	...localizedConfig,
	autoSendOnPublish: localizedConfig.autoSendOnPublish ?? true,
	delayedSendSeconds:
		typeof localizedConfig.delayedSendSeconds === 'number'
			? localizedConfig.delayedSendSeconds
			: 600,
};

export function isEmailPostType(postType: string | undefined): boolean {
	if (!postType) {
		return false;
	}
	const types = config.postTypes ?? [
		CAMPAIGN_POST_TYPE,
		TRANSACTIONAL_POST_TYPE,
	];
	return types.includes(postType);
}

export function isCampaignPostType(postType: string | undefined): boolean {
	return postType === (config.campaignPostType ?? CAMPAIGN_POST_TYPE);
}

export function isTransactionalPostType(postType: string | undefined): boolean {
	return (
		postType === (config.transactionalPostType ?? TRANSACTIONAL_POST_TYPE)
	);
}

export function useNewsletterLists() {
	const records = useSelect((select) => {
		const query = { per_page: -1, context: 'edit' as const };
		return select(coreStore).getEntityRecords(
			'taxonomy',
			NEWSLETTER_LIST_TAXONOMY,
			query
		) as NewsletterListTerm[] | null;
	}, []);

	return {
		lists: records ?? [],
		loading: records === null,
	};
}

export function useNewsletterMeta() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const [meta, setMeta] = useEntityProp('postType', postType, 'meta', postId);
	const { editPost } = useDispatch(editorStore);

	const persistMeta = useCallback(
		(patch: Record<string, string>) => {
			setMeta(patch);
			editPost({ meta: patch });
		},
		[setMeta, editPost]
	);
	const { lists, loading: listsLoading } = useNewsletterLists();

	const assignedListTermIds: number[] = useSelect((select) => {
		const raw = select(editorStore).getEditedPostAttribute(
			NEWSLETTER_LIST_TAXONOMY
		);
		if (!Array.isArray(raw)) {
			return [];
		}
		return raw
			.map((id) => Number(id))
			.filter((id) => Number.isFinite(id) && id > 0);
	}, []);

	const selectedListTermId = assignedListTermIds[0] ?? 0;
	const hasListTerm = selectedListTermId > 0;
	const selectedListTerm = lists.find(
		(list) => Number(list.id) === selectedListTermId
	);
	const listAudienceId = String(
		selectedListTerm?.meta?.prc_newsletter_list_audience_id ?? ''
	);
	const listSegmentId = String(
		selectedListTerm?.meta?.prc_newsletter_list_segment_id ?? ''
	);

	const subject: string = meta?.prc_email_subject ?? '';
	const previewText: string = meta?.prc_email_preview_text ?? '';
	const rawDeliveryMode: string = meta?.prc_email_delivery_mode ?? '';
	const deliveryMode: TransactionalDeliveryMode =
		rawDeliveryMode === 'mandrill' ? 'mandrill' : 'dynamic';
	const audienceId: string = String(
		meta?.prc_email_mailchimp_audience_id ?? ''
	);
	const segmentId: string = String(
		meta?.prc_email_mailchimp_segment_id ?? ''
	);
	const campaignId: string = meta?.prc_email_mailchimp_campaign_id ?? '';
	const campaignAdminUrl: string =
		meta?.prc_email_mailchimp_campaign_admin_url ?? '';
	const campaignStatus: string =
		meta?.prc_email_mailchimp_campaign_status ?? '';
	const pendingSendAtRaw = meta?.prc_email_mailchimp_pending_send_at;
	const pendingSendAtParsed =
		typeof pendingSendAtRaw === 'number'
			? pendingSendAtRaw
			: parseInt(String(pendingSendAtRaw ?? ''), 10);
	const pendingSendAt =
		Number.isFinite(pendingSendAtParsed) && pendingSendAtParsed > 0
			? pendingSendAtParsed
			: 0;
	const cancelledDelayedSend =
		String(meta?.prc_email_mailchimp_delayed_send_cancelled ?? '') === '1';
	const audienceOptionKey: string = meta?.prc_email_audience_option_key ?? '';
	const mandrillSendStatus: string =
		meta?.prc_email_mandrill_send_status ?? '';
	let mandrillSendSummary: Record<string, number | string> | null = null;
	const mandrillSendSummaryRaw: string =
		meta?.prc_email_mandrill_send_summary ?? '';
	if (mandrillSendSummaryRaw) {
		try {
			mandrillSendSummary = JSON.parse(mandrillSendSummaryRaw) as Record<
				string,
				number | string
			>;
		} catch {
			mandrillSendSummary = null;
		}
	}
	const templateSlug: string = meta?.prc_email_template_slug ?? '';
	const slug: string =
		useSelect((select) => select(editorStore).getEditedPostSlug(), []) ??
		'';

	const setSubject = (value: string) =>
		persistMeta({ prc_email_subject: value });
	const setPreviewText = (value: string) =>
		persistMeta({ prc_email_preview_text: value });
	const setDeliveryMode = (value: string) =>
		persistMeta({ prc_email_delivery_mode: value });
	// Changing the audience resets the segment selection.
	const setAudienceId = (value: string) =>
		persistMeta({
			prc_email_mailchimp_audience_id: value,
			prc_email_mailchimp_segment_id: '',
		});
	const setSegmentId = (value: string) =>
		persistMeta({ prc_email_mailchimp_segment_id: value });
	const setAudienceOptionKey = (value: string) =>
		persistMeta({ prc_email_audience_option_key: value });
	const setTemplateSlug = (value: string) =>
		persistMeta({ prc_email_template_slug: value });
	const setSlug = (value: string) => editPost({ slug: value });

	const selectNewsletterList = useCallback(
		(termIdStr: string) => {
			const termId = termIdStr ? parseInt(termIdStr, 10) : 0;
			if (!termId) {
				editPost({ [NEWSLETTER_LIST_TAXONOMY]: [] });
				return;
			}

			const term = lists.find((list) => Number(list.id) === termId);
			const nextAudienceId = String(
				term?.meta?.prc_newsletter_list_audience_id ?? ''
			);
			const nextSegmentId = String(
				term?.meta?.prc_newsletter_list_segment_id ?? ''
			);

			editPost({ [NEWSLETTER_LIST_TAXONOMY]: [termId] });
			persistMeta({
				prc_email_mailchimp_audience_id: nextAudienceId,
				prc_email_mailchimp_segment_id: nextSegmentId,
			});
		},
		[editPost, lists, persistMeta]
	);

	// Align campaign Mailchimp meta with the assigned list term (load + term updates).
	useEffect(() => {
		if (!hasListTerm || listsLoading || !selectedListTerm) {
			return;
		}
		if (audienceId === listAudienceId && segmentId === listSegmentId) {
			return;
		}
		persistMeta({
			prc_email_mailchimp_audience_id: listAudienceId,
			prc_email_mailchimp_segment_id: listSegmentId,
		});
	}, [
		hasListTerm,
		listsLoading,
		selectedListTerm,
		listAudienceId,
		listSegmentId,
		audienceId,
		segmentId,
		persistMeta,
	]);

	const effectiveAudienceId =
		hasListTerm && selectedListTerm ? listAudienceId : audienceId;
	const effectiveSegmentId =
		hasListTerm && selectedListTerm ? listSegmentId : segmentId;

	return {
		postType,
		rawDeliveryMode,
		subject,
		previewText,
		deliveryMode,
		audienceId: effectiveAudienceId,
		segmentId: effectiveSegmentId,
		campaignId,
		campaignAdminUrl,
		campaignStatus,
		pendingSendAt,
		cancelledDelayedSend,
		audienceOptionKey,
		mandrillSendStatus,
		mandrillSendSummary,
		templateSlug,
		slug,
		selectedListTermId,
		hasListTerm,
		setSubject,
		setPreviewText,
		setDeliveryMode,
		setAudienceId,
		setSegmentId,
		setAudienceOptionKey,
		setTemplateSlug,
		setSlug,
		selectNewsletterList,
	};
}

export function useAudiences() {
	const [audiences, setAudiences] = useState<Audience[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;
		apiFetch<Audience[]>({
			path: `/${config.restNamespace}/audiences`,
		})
			.then((data) => {
				if (!cancelled) {
					setAudiences(data);
					setLoading(false);
				}
			})
			.catch((err) => {
				if (!cancelled) {
					setError(
						err?.message ??
							__('Could not load audiences.', 'prc-email-builder')
					);
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	return { audiences, loading, error };
}

export function useSegments(audienceId: string) {
	const [segments, setSegments] = useState<Segment[]>([]);
	const [loading, setLoading] = useState(false);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		if (!audienceId) {
			setSegments([]);
			setError(null);
			return;
		}

		let cancelled = false;
		setLoading(true);

		apiFetch<Segment[]>({
			path: `/${config.restNamespace}/audiences/${encodeURIComponent(audienceId)}/segments`,
		})
			.then((data) => {
				if (!cancelled) {
					setSegments(data);
					setLoading(false);
				}
			})
			.catch((err) => {
				if (!cancelled) {
					setError(
						err?.message ??
							__('Could not load segments.', 'prc-email-builder')
					);
					setLoading(false);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [audienceId]);

	return { segments, loading, error };
}

export function useSystemAudiences() {
	const [audiences, setAudiences] = useState<SystemAudience[]>([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		let cancelled = false;
		apiFetch<SystemAudience[]>({
			path: `/${config.restNamespace}/audiences-system`,
		})
			.then((data) => {
				if (!cancelled) {
					setAudiences(data);
					setLoading(false);
				}
			})
			.catch((err) => {
				if (!cancelled) {
					setError(
						err?.message ??
							__(
								'Could not load system audiences.',
								'prc-email-builder'
							)
					);
					setLoading(false);
				}
			});
		return () => {
			cancelled = true;
		};
	}, []);

	return { audiences, loading, error };
}

/**
 * Fetches preview readiness from GET /preview (deterministic rendering).
 *
 * @param postId Newsletter post ID.
 */
export function useEmailPreviewStatus(postId: number) {
	const [status, setStatus] = useState<PreviewStatus>('none');
	const [isRefreshing, setIsRefreshing] = useState(false);

	const refresh = useCallback(async () => {
		if (!postId) {
			return;
		}
		setIsRefreshing(true);
		try {
			const data = await apiFetch<{ status: string }>({
				path: `/${config.restNamespace}/preview?post_id=${postId}`,
			});
			setStatus((data.status as PreviewStatus) ?? 'complete');
		} catch {
			setStatus('error');
		} finally {
			setIsRefreshing(false);
		}
	}, [postId]);

	useEffect(() => {
		if (!postId) {
			return;
		}
		void refresh();
	}, [postId, refresh]);

	return { status, refresh, isRefreshing };
}

/**
 * @deprecated Use useEmailPreviewStatus.
 *
 * @param      postId Newsletter post ID.
 */
export function useTransformStatus(postId: number) {
	const { status, refresh, isRefreshing } = useEmailPreviewStatus(postId);
	return {
		status,
		trigger: refresh,
		isTriggering: isRefreshing,
	};
}

export { useSyncMailchimpCampaignMeta } from './use-sync-mailchimp-campaign-meta';

const MANDRILL_SYNC_POLL_MS = 3000;
const MANDRILL_SYNC_MAX_ATTEMPTS = 40;

const MANDRILL_TERMINAL_STATUSES = new Set(['sent', 'partial', 'failed']);

/**
 * Poll server post meta while a Mandrill send runs in Action Scheduler.
 *
 * @param postId     Newsletter post ID.
 * @param shouldSync When true, poll until send status leaves "sending".
 */
export function useSyncMandrillSendMeta(
	postId: number,
	shouldSync: boolean
): void {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const { editPost } = useDispatch(editorStore);

	useEffect(() => {
		if (!shouldSync || !postId || !postType) {
			return undefined;
		}

		let cancelled = false;
		let attempts = 0;
		let timer: ReturnType<typeof setInterval> | undefined;

		const stopPolling = () => {
			if (timer) {
				clearInterval(timer);
				timer = undefined;
			}
		};

		const sync = () => {
			if (cancelled) {
				return;
			}
			attempts += 1;
			if (attempts > MANDRILL_SYNC_MAX_ATTEMPTS) {
				stopPolling();
				return;
			}

			apiFetch<{ meta?: Record<string, string> }>({
				path: `/wp/v2/${postType}/${postId}?context=edit&_fields=meta`,
			})
				.then((data) => {
					if (cancelled) {
						return;
					}
					const status =
						data.meta?.prc_email_mandrill_send_status ?? '';
					if (!status || !MANDRILL_TERMINAL_STATUSES.has(status)) {
						return;
					}
					stopPolling();
					editPost({
						meta: {
							prc_email_mandrill_send_status: status,
							...(data.meta?.prc_email_mandrill_send_summary
								? {
										prc_email_mandrill_send_summary:
											data.meta
												.prc_email_mandrill_send_summary,
									}
								: {}),
						},
					});
				})
				.catch(() => {
					// Keep polling through transient REST errors.
				});
		};

		sync();
		timer = setInterval(sync, MANDRILL_SYNC_POLL_MS);
		return () => {
			cancelled = true;
			stopPolling();
		};
	}, [postId, postType, shouldSync, editPost]);
}

// Re-export for convenience of panel files.
export { __ };
