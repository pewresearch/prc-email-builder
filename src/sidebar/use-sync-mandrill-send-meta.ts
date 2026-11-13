import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';

import {
	mandrillPollCadence,
	planMandrillSync,
	type MandrillServerMeta,
} from './plan-mandrill-sync';

/**
 * Poll server post meta while a Mandrill send runs in Action Scheduler, or
 * while it waits at needs_review.
 *
 * @param postId         Newsletter post ID.
 * @param shouldSync     When true, poll until send status leaves the watched status. See mandrillPollCadence() for the rate and the cap.
 * @param watched        Status the editor shows now. Polling stops when the server reports another terminal status.
 * @param watchedSummary Raw send summary the editor shows now. A different server summary is applied while the status stays.
 */
export function useSyncMandrillSendMeta(
	postId: number,
	shouldSync: boolean,
	watched = 'sending',
	watchedSummary = ''
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

		const { intervalMs, maxAttempts } = mandrillPollCadence(watched);
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
			if (attempts > maxAttempts) {
				stopPolling();
				return;
			}

			apiFetch<{ meta?: MandrillServerMeta }>({
				path: `/wp/v2/${postType}/${postId}?context=edit&_fields=meta`,
			})
				.then((data) => {
					if (cancelled) {
						return;
					}
					const plan = planMandrillSync(
						data.meta,
						watched,
						watchedSummary
					);
					if (plan.stop) {
						stopPolling();
					}
					if (plan.patch) {
						editPost({ meta: plan.patch });
					}
				})
				.catch(() => {
					// Keep polling through transient REST errors.
				});
		};

		sync();
		timer = setInterval(sync, intervalMs);
		return () => {
			cancelled = true;
			stopPolling();
		};
	}, [postId, postType, shouldSync, watched, watchedSummary, editPost]);
}
