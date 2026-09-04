import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

import {
	type JobView,
	type StartAudienceInput,
	shouldPollAudience,
} from './auth-domain-audience-types';

const POLL_INTERVAL_MS = 4000;
const REST_PATH = '/prc-email-builder/v1/auth-domain-audiences';

export function useAuthDomainAudienceJob(isOpen: boolean) {
	const [view, setView] = useState<JobView | null>(null);
	const [error, setError] = useState<string | null>(null);
	const [isStarting, setIsStarting] = useState(false);
	const [isCreatingDraft, setIsCreatingDraft] = useState(false);

	const reset = useCallback(() => {
		setView(null);
		setError(null);
		setIsStarting(false);
		setIsCreatingDraft(false);
	}, []);

	useEffect(() => {
		if (!isOpen) {
			reset();
		}
	}, [isOpen, reset]);

	useEffect(() => {
		if (!isOpen || view === null || !shouldPollAudience(view)) {
			return;
		}

		let cancelled = false;
		let timeout = 0;

		const enqueuePoll = () => {
			timeout = window.setTimeout(() => {
				void apiFetch<JobView>({
					path: `${REST_PATH}/${view.jobId}`,
				})
					.then((nextView) => {
						if (cancelled) {
							return;
						}
						setView(nextView);
						setError(null);
					})
					.catch((reason: unknown) => {
						if (cancelled) {
							return;
						}
						setError(getErrorMessage(reason));
						enqueuePoll();
					});
			}, POLL_INTERVAL_MS);
		};

		enqueuePoll();

		return () => {
			cancelled = true;
			window.clearTimeout(timeout);
		};
	}, [isOpen, view]);

	const start = useCallback(async (input: StartAudienceInput) => {
		setIsStarting(true);
		setError(null);
		try {
			const nextView = await apiFetch<JobView>({
				path: REST_PATH,
				method: 'POST',
				data: input,
			});
			setView(nextView);
		} catch (reason) {
			setError(getErrorMessage(reason));
		} finally {
			setIsStarting(false);
		}
	}, []);

	const createDraft = useCallback(async () => {
		if (view?.phase !== 'ready') {
			return;
		}

		setIsCreatingDraft(true);
		setError(null);
		try {
			const nextView = await apiFetch<JobView>({
				path: `${REST_PATH}/${view.jobId}/draft`,
				method: 'POST',
			});
			setView(nextView);
			if (
				nextView.phase === 'ready' &&
				nextView.draft.status === 'created'
			) {
				window.location.assign(nextView.draft.editUrl);
			}
		} catch (reason) {
			setError(getErrorMessage(reason));
		} finally {
			setIsCreatingDraft(false);
		}
	}, [view]);

	return {
		view,
		error,
		isStarting,
		isCreatingDraft,
		start,
		createDraft,
		reset,
	};
}

function getErrorMessage(reason: unknown): string {
	if (reason instanceof Error && reason.message) {
		return reason.message;
	}

	if (
		typeof reason === 'object' &&
		reason !== null &&
		'message' in reason &&
		typeof reason.message === 'string' &&
		reason.message !== ''
	) {
		return reason.message;
	}

	return 'The audience request failed.';
}
