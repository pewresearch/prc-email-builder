/**
 * Hook: usePreview
 *
 * Fetches email HTML from GET /prc-email-builder/v1/preview.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const config: {
	restNamespace: string;
	defaults?: { from_name: string; from_email: string };
} = (window as any).prcEmailBuilderConfig ?? {};

export type PreviewStatus = 'idle' | 'loading' | 'complete' | 'error';

export interface PreviewData {
	status: 'none' | 'pending' | 'complete' | 'error';
	html: string;
	size_bytes: number;
	from_name: string;
	from_email: string;
	subject: string;
	preview_text: string;
	error?: string;
}

export interface UsePreviewReturn {
	fetchStatus: PreviewStatus;
	data: PreviewData | null;
	errorMessage: string | null;
	refresh: () => Promise<void>;
}

export function usePreview(postId: number, isOpen: boolean): UsePreviewReturn {
	const [fetchStatus, setFetchStatus] = useState<PreviewStatus>('idle');
	const [data, setData] = useState<PreviewData | null>(null);
	const [errorMessage, setErrorMessage] = useState<string | null>(null);

	const fetchPreview = useCallback(async () => {
		if (!postId) {
			return;
		}
		setFetchStatus('loading');
		setErrorMessage(null);

		try {
			const result = await apiFetch<PreviewData>({
				path: `/${config.restNamespace}/preview?post_id=${postId}`,
			});

			setData(result);

			if (result.status === 'complete') {
				setFetchStatus('complete');
			} else if (result.status === 'error') {
				setFetchStatus('error');
				setErrorMessage(result.error ?? 'Unknown error.');
			} else {
				setFetchStatus('error');
				setErrorMessage('Preview did not return complete HTML.');
			}
		} catch (err: any) {
			setFetchStatus('error');
			setErrorMessage(err?.message ?? 'Failed to fetch preview.');
		}
	}, [postId]);

	useEffect(() => {
		if (!isOpen || !postId) {
			return;
		}

		void fetchPreview();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- stable open/postId fetch only
	}, [isOpen, postId]);

	const refresh = useCallback(async () => {
		await fetchPreview();
	}, [fetchPreview]);

	return { fetchStatus, data, errorMessage, refresh };
}
