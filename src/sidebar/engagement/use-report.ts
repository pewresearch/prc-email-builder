import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

export interface ReportSummary {
	emails_sent?: number;
	opens_total?: number;
	opens_unique?: number;
	open_rate?: number;
	clicks_total?: number;
	clicks_unique?: number;
	click_rate?: number;
	bounces_hard?: number;
	bounces_soft?: number;
	unsubscribes?: number;
	abuse_reports?: number;
}

export interface ReportClickRow {
	url: string;
	clicks: number;
}

export interface NormalizedReport {
	channel?: string;
	summary?: ReportSummary;
	clicks_by_url?: ReportClickRow[];
	send_time?: string;
}

export interface ReportEnvelope {
	report: NormalizedReport | null;
	sync_state: string;
	last_synced: string;
	open_rate: number;
	click_rate: number;
	mailchimp_status: string;
}

export function formatRate(rate: number | undefined): string {
	if (rate === undefined || Number.isNaN(rate)) {
		return '—';
	}
	return `${(rate * 100).toFixed(1)}%`;
}

export function useCampaignReport(postId: number | undefined) {
	const [data, setData] = useState<ReportEnvelope | null>(null);
	const [isLoading, setIsLoading] = useState(false);
	const [isRefreshing, setIsRefreshing] = useState(false);
	const [error, setError] = useState<string | null>(null);

	const load = useCallback(async () => {
		if (!postId) {
			return;
		}
		setIsLoading(true);
		setError(null);
		// Drop prior campaign data so consumers never mix title/metrics across ids.
		setData(null);
		try {
			const response = await apiFetch<ReportEnvelope>({
				path: `/prc-email-builder/v1/campaigns/${postId}/report`,
			});
			setData(response);
		} catch (err: unknown) {
			const message =
				err instanceof Error ? err.message : 'Failed to load report.';
			setError(message);
		} finally {
			setIsLoading(false);
		}
	}, [postId]);

	const refresh = useCallback(async () => {
		if (!postId) {
			return;
		}
		setIsRefreshing(true);
		setError(null);
		try {
			const response = await apiFetch<ReportEnvelope>({
				path: `/prc-email-builder/v1/campaigns/${postId}/report/refresh`,
				method: 'POST',
			});
			setData(response);
		} catch (err: unknown) {
			const message =
				err instanceof Error ? err.message : 'Refresh failed.';
			setError(message);
		} finally {
			setIsRefreshing(false);
		}
	}, [postId]);

	useEffect(() => {
		load();
	}, [load]);

	return {
		data,
		isLoading,
		isRefreshing,
		error,
		refresh,
		reload: load,
	};
}
