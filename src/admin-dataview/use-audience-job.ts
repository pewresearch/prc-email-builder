import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

import { shouldPollJob } from './audience-catalog';
import type { DraftOutcome, AudienceRef } from './auth-domain-audience-types';

const POLL_INTERVAL_MS = 4000;
const REST_PATH = '/prc-email-builder/v1/audience-jobs';

export interface AudienceJobView {
	readonly jobId: string;
	readonly builder: string;
	readonly phase: 'queued' | 'scanning' | 'ready' | 'failed';
	readonly label: string;
	readonly query: Record<string, unknown>;
	readonly dryRun?: boolean;
	readonly scannedUsers?: number | null;
	readonly matchedUsers?: number | null;
	readonly scannedGroups?: number | null;
	readonly v2Groups?: number | null;
	readonly count?: number;
	readonly audience?: AudienceRef;
	readonly draft?: DraftOutcome;
	readonly error?: { readonly code: string; readonly message: string };
}

export function useAudienceJob(isOpen: boolean) {
	const [view, setView] = useState<AudienceJobView | null>(null);
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
		if (!isOpen || view === null || !shouldPollJob(view.phase)) {
			return;
		}

		let cancelled = false;
		let timeout = 0;

		const enqueuePoll = () => {
			timeout = window.setTimeout(() => {
				void apiFetch<AudienceJobView>({
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

	const start = useCallback(async (input: Record<string, unknown>) => {
		setIsStarting(true);
		setError(null);
		try {
			const nextView = await apiFetch<AudienceJobView>({
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
			const nextView = await apiFetch<AudienceJobView>({
				path: `${REST_PATH}/${view.jobId}/draft`,
				method: 'POST',
			});
			setView(nextView);
			if (
				nextView.phase === 'ready' &&
				nextView.draft &&
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
