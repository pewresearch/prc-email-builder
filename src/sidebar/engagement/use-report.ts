import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

export interface ReportSummary {
	emails_sent?: number | null;
	opens_total?: number | null;
	opens_unique?: number | null;
	open_rate?: number | null;
	clicks_total?: number | null;
	clicks_unique?: number | null;
	click_rate?: number | null;
	bounces_hard?: number | null;
	bounces_soft?: number | null;
	unsubscribes?: number | null;
	abuse_reports?: number | null;
	completions?: number | null;
}

export interface ReportClickRow {
	url: string;
	clicks: number;
	unique?: number;
}

export interface NormalizedReport {
	channel?: string;
	summary?: ReportSummary;
	clicks_by_url?: ReportClickRow[];
	send_time?: string;
}

export interface Coverage {
	volume: 'complete' | 'none' | string;
	engagement: 'full' | 'forward_only' | 'none' | string;
	audience: 'full' | 'crm_contacts' | string;
	engagement_since?: string | null;
	tracked_emails_sent?: number | null;
}

export interface ReportEnvelope {
	report: NormalizedReport | null;
	sync_state: string;
	last_synced: string;
	open_rate: number | null;
	click_rate: number | null;
	mailchimp_status: string;
	delivery_status?: string;
	channel?: string;
	stats_available?: boolean;
	coverage?: Coverage;
}

export function formatRate(rate: number | null | undefined): string {
	if (rate === undefined || rate === null || Number.isNaN(rate)) {
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
