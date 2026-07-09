import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import {
	Button,
	Notice,
	Spinner,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import { isCampaignPostType } from '../use-newsletter-data';
import {
	formatRate,
	useCampaignReport,
	type ReportClickRow,
} from './use-report';
import './style.scss';

const VISIBLE_CLICKS_LIMIT = 5;
const DISPLAY_URL_MAX_LENGTH = 72;

function formatLastSynced(utcIso: string): string {
	if (!utcIso) {
		return '';
	}
	const date = new Date(utcIso);
	if (Number.isNaN(date.getTime())) {
		return utcIso;
	}
	return sprintf(
		/* translators: %s: site-local datetime */
		__('Last updated %s', 'prc-email-builder'),
		date.toLocaleString()
	);
}

function formatNumber(value: number | undefined): string {
	if (value === undefined || value === null || Number.isNaN(value)) {
		return '0';
	}
	return value.toLocaleString();
}

// Strip tracking params for display; full URL stays on the link title/href.
function formatDisplayUrl(
	rawUrl: string,
	maxLength = DISPLAY_URL_MAX_LENGTH
): string {
	try {
		const url = new URL(rawUrl);

		[...url.searchParams.keys()].forEach((key) => {
			if (
				key.startsWith('utm_') ||
				key.startsWith('mc_') ||
				key === 'fbclid' ||
				key === 'gclid'
			) {
				url.searchParams.delete(key);
			}
		});

		const path = url.pathname === '/' ? '' : url.pathname;
		const query = url.search;
		let display = `${url.hostname}${path}${query}`;

		if (display.length > maxLength) {
			display = `${display.slice(0, maxLength - 1)}…`;
		}

		return display;
	} catch {
		if (rawUrl.length <= maxLength) {
			return rawUrl;
		}
		return `${rawUrl.slice(0, maxLength - 1)}…`;
	}
}

function getVisibleClicks(clicks: ReportClickRow[]): {
	visible: ReportClickRow[];
	remainder: number;
} {
	const nonZero = clicks.filter((row) => row.clicks > 0);
	const visible = nonZero.slice(0, VISIBLE_CLICKS_LIMIT);
	return {
		visible,
		remainder: Math.max(0, nonZero.length - visible.length),
	};
}

function MetricRow({
	label,
	value,
}: {
	label: string;
	value: string | number;
}) {
	return (
		<div className="prc-email-engagement-metric">
			<span className="prc-email-engagement-metric__label">{label}</span>
			<span className="prc-email-engagement-metric__value">{value}</span>
		</div>
	);
}

function MetricSection({
	title,
	children,
}: {
	title?: string;
	children: ReactNode;
}) {
	return (
		<VStack spacing={1}>
			{title ? (
				<p className="prc-email-engagement-section-title">{title}</p>
			) : null}
			{children}
		</VStack>
	);
}

function ClickedLinksList({ clicks }: { clicks: ReportClickRow[] }) {
	const { visible, remainder } = getVisibleClicks(clicks);

	if (visible.length === 0) {
		return (
			<Text variant="muted">
				{__('No link clicks recorded yet.', 'prc-email-builder')}
			</Text>
		);
	}

	return (
		<VStack spacing={1}>
			<p className="prc-email-engagement-section-title">
				{__('Top clicked links', 'prc-email-builder')}
			</p>
			<ul className="prc-email-engagement-clicks">
				{visible.map((row) => (
					<li key={row.url} className="prc-email-engagement-click">
						<span className="prc-email-engagement-click__count">
							{formatNumber(row.clicks)}
						</span>
						<a
							className="prc-email-engagement-click__url"
							href={row.url}
							target="_blank"
							rel="noopener noreferrer"
							title={row.url}
						>
							{formatDisplayUrl(row.url)}
						</a>
					</li>
				))}
			</ul>
			{remainder > 0 ? (
				<p className="prc-email-engagement-clicks-more">
					{sprintf(
						/* translators: %d: number of additional clicked links not shown */
						__('and %d more', 'prc-email-builder'),
						remainder
					)}
				</p>
			) : null}
		</VStack>
	);
}

export function EngagementPanel() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);

	const { data, isLoading, isRefreshing, error, refresh } =
		useCampaignReport(postId);

	if (!isCampaignPostType(postType)) {
		return null;
	}

	const mailchimpStatus = data?.mailchimp_status ?? '';
	const syncState = data?.sync_state ?? '';
	const report = data?.report;
	const isSent = mailchimpStatus === 'sent';

	let body: ReactNode = null;

	if (isLoading && !data) {
		body = <Spinner />;
	} else if (!isSent) {
		body = (
			<Text>
				{__(
					'Engagement reporting is available after this campaign is sent in Mailchimp.',
					'prc-email-builder'
				)}
			</Text>
		);
	} else if (syncState === 'unavailable') {
		body = (
			<Text>
				{__(
					'Report unavailable in Mailchimp. The linked campaign may have been deleted.',
					'prc-email-builder'
				)}
			</Text>
		);
	} else if (!report) {
		body = (
			<VStack spacing={2}>
				<Text>
					{__(
						'Report pending — no engagement data synced yet.',
						'prc-email-builder'
					)}
				</Text>
				<Button
					variant="secondary"
					onClick={refresh}
					disabled={isRefreshing}
					isBusy={isRefreshing}
				>
					{__('Refresh now', 'prc-email-builder')}
				</Button>
			</VStack>
		);
	} else {
		const summary = report.summary ?? {};
		const clicks = report.clicks_by_url ?? [];
		const hardBounces = summary.bounces_hard ?? 0;
		const softBounces = summary.bounces_soft ?? 0;
		const unsubscribes = summary.unsubscribes ?? 0;
		const abuseReports = summary.abuse_reports ?? 0;
		const hasHealthMetrics =
			hardBounces > 0 ||
			softBounces > 0 ||
			unsubscribes > 0 ||
			abuseReports > 0;

		body = (
			<VStack spacing={3}>
				<MetricSection>
					<MetricRow
						label={__('Emails sent', 'prc-email-builder')}
						value={formatNumber(summary.emails_sent)}
					/>
					<MetricRow
						label={__('Open rate', 'prc-email-builder')}
						value={formatRate(summary.open_rate)}
					/>
					<MetricRow
						label={__('Click rate', 'prc-email-builder')}
						value={formatRate(summary.click_rate)}
					/>
					<MetricRow
						label={__('Unique opens', 'prc-email-builder')}
						value={formatNumber(summary.opens_unique)}
					/>
					<MetricRow
						label={__('Unique clicks', 'prc-email-builder')}
						value={formatNumber(summary.clicks_unique)}
					/>
				</MetricSection>

				{hasHealthMetrics ? (
					<MetricSection
						title={__('Delivery health', 'prc-email-builder')}
					>
						<MetricRow
							label={__('Hard bounces', 'prc-email-builder')}
							value={formatNumber(hardBounces)}
						/>
						<MetricRow
							label={__('Soft bounces', 'prc-email-builder')}
							value={formatNumber(softBounces)}
						/>
						<MetricRow
							label={__('Unsubscribes', 'prc-email-builder')}
							value={formatNumber(unsubscribes)}
						/>
						<MetricRow
							label={__('Abuse reports', 'prc-email-builder')}
							value={formatNumber(abuseReports)}
						/>
					</MetricSection>
				) : null}

				<ClickedLinksList clicks={clicks} />

				{data?.last_synced ? (
					<p className="prc-email-engagement-last-updated">
						{formatLastSynced(data.last_synced)}
					</p>
				) : null}

				<Button
					variant="secondary"
					onClick={refresh}
					disabled={isRefreshing}
					isBusy={isRefreshing}
				>
					{__('Refresh now', 'prc-email-builder')}
				</Button>
			</VStack>
		);
	}

	return (
		<PluginDocumentSettingPanel
			name="prc-email-engagement"
			title={__('Engagement', 'prc-email-builder')}
			className="prc-email-engagement-panel"
		>
			<VStack spacing={2}>
				{error ? (
					<Notice status="warning" isDismissible={false}>
						{error}
					</Notice>
				) : null}
				{body}
			</VStack>
		</PluginDocumentSettingPanel>
	);
}

export default EngagementPanel;
