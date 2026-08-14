import type { ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import {
	TabPanel,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import {
	buildDeliverySegments,
	buildEngagementSegments,
	type ChartSegmentId,
	type NumericSegment,
} from './engagement-model';
import {
	MetricGrid,
	type MetricCardModel,
	type MetricGroupModel,
} from './metric-grid';
import { StackedBar, type StackedBarProps } from './stacked-bar';
import {
	formatRate,
	type ReportClickRow,
	type ReportSummary,
} from './use-report';

const VISIBLE_CLICKS_LIMIT = 5;
const DISPLAY_URL_MAX_LENGTH = 72;

type CountMetricField =
	| 'emails_sent'
	| 'opens_unique'
	| 'clicks_unique'
	| 'bounces_hard'
	| 'bounces_soft'
	| 'unsubscribes'
	| 'abuse_reports';

type RateMetricField = 'open_rate' | 'click_rate';
type MetricGroupId = 'engagement' | 'delivery';
type MetricGroupVisibility = 'always' | 'when-group-has-value';

interface CountMetricDefinition {
	id: string;
	field: CountMetricField;
	label: string;
	format: 'count';
}

interface RateMetricDefinition {
	id: string;
	field: RateMetricField;
	label: string;
	format: 'rate';
}

type MetricDefinition = CountMetricDefinition | RateMetricDefinition;

interface MetricGroupDefinition {
	id: MetricGroupId;
	title: string | null;
	visibility: MetricGroupVisibility;
	metrics: MetricDefinition[];
}

interface ChartDefinition {
	id: 'engagement' | 'delivery';
	title: string;
	description: string;
	emptyMessage: string;
	buildSegments: (
		summary: ReportSummary
	) => readonly NumericSegment<ChartSegmentId>[];
}

const METRIC_GROUPS = [
	{
		id: 'engagement',
		title: null,
		visibility: 'always',
		metrics: [
			{
				id: 'emails-sent',
				field: 'emails_sent',
				label: __('Emails sent', 'prc-email-builder'),
				format: 'count',
			},
			{
				id: 'open-rate',
				field: 'open_rate',
				label: __('Open rate', 'prc-email-builder'),
				format: 'rate',
			},
			{
				id: 'click-rate',
				field: 'click_rate',
				label: __('Click rate', 'prc-email-builder'),
				format: 'rate',
			},
			{
				id: 'unique-opens',
				field: 'opens_unique',
				label: __('Unique opens', 'prc-email-builder'),
				format: 'count',
			},
			{
				id: 'unique-clicks',
				field: 'clicks_unique',
				label: __('Unique clicks', 'prc-email-builder'),
				format: 'count',
			},
		],
	},
	{
		id: 'delivery',
		title: __('Delivery health', 'prc-email-builder'),
		visibility: 'when-group-has-value',
		metrics: [
			{
				id: 'hard-bounces',
				field: 'bounces_hard',
				label: __('Hard bounces', 'prc-email-builder'),
				format: 'count',
			},
			{
				id: 'soft-bounces',
				field: 'bounces_soft',
				label: __('Soft bounces', 'prc-email-builder'),
				format: 'count',
			},
			{
				id: 'unsubscribes',
				field: 'unsubscribes',
				label: __('Unsubscribes', 'prc-email-builder'),
				format: 'count',
			},
			{
				id: 'abuse-reports',
				field: 'abuse_reports',
				label: __('Abuse reports', 'prc-email-builder'),
				format: 'count',
			},
		],
	},
] satisfies MetricGroupDefinition[];

const SEGMENT_LABELS = {
	clicked: __('Clicked', 'prc-email-builder'),
	'opened-no-click': __('Opened, no click', 'prc-email-builder'),
	'not-opened': __('Not opened', 'prc-email-builder'),
	'hard-bounces': __('Hard bounces', 'prc-email-builder'),
	'soft-bounces': __('Soft bounces', 'prc-email-builder'),
	unsubscribes: __('Unsubscribes', 'prc-email-builder'),
	'abuse-reports': __('Abuse reports', 'prc-email-builder'),
} satisfies Record<ChartSegmentId, string>;

const CHART_DEFINITIONS = [
	{
		id: 'engagement',
		title: __('Engagement', 'prc-email-builder'),
		description: __(
			'Recipient outcomes as a share of emails sent.',
			'prc-email-builder'
		),
		emptyMessage: __('No emails sent.', 'prc-email-builder'),
		buildSegments: buildEngagementSegments,
	},
	{
		id: 'delivery',
		title: __('Delivery issues', 'prc-email-builder'),
		description: __(
			'Issue counts as a share of all recorded delivery issues.',
			'prc-email-builder'
		),
		emptyMessage: __('No delivery issues recorded.', 'prc-email-builder'),
		buildSegments: buildDeliverySegments,
	},
] satisfies ChartDefinition[];

function formatNumber(value: number | undefined): string {
	if (value === undefined || value === null || Number.isNaN(value)) {
		return '0';
	}
	return value.toLocaleString();
}

function buildMetricGroups(
	summary: ReportSummary,
	definitions: readonly MetricGroupDefinition[]
): readonly MetricGroupModel[] {
	return definitions.flatMap((group) => {
		const cards: MetricCardModel[] = group.metrics.map((metric) => {
			const rawValue = summary[metric.field];
			return {
				id: metric.id,
				label: metric.label,
				rawValue,
				displayValue:
					metric.format === 'rate'
						? formatRate(rawValue)
						: formatNumber(rawValue),
			};
		});

		if (group.visibility === 'when-group-has-value') {
			const hasValue = cards.some(
				(card) =>
					typeof card.rawValue === 'number' &&
					Number.isFinite(card.rawValue) &&
					card.rawValue > 0
			);
			if (!hasValue) {
				return [];
			}
		}

		return [
			{
				id: group.id,
				title: group.title,
				cards,
			},
		];
	});
}

function buildCharts(summary: ReportSummary): readonly StackedBarProps[] {
	return CHART_DEFINITIONS.flatMap((definition) => {
		const segments = definition.buildSegments(summary);
		if (
			definition.id === 'delivery' &&
			!segments.some((segment) => segment.value > 0)
		) {
			return [];
		}

		return [
			{
				title: definition.title,
				description: definition.description,
				emptyMessage: definition.emptyMessage,
				segments: segments.map((segment) => ({
					...segment,
					label: SEGMENT_LABELS[segment.id],
					displayValue: segment.value.toLocaleString(),
				})),
			},
		];
	});
}

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

function OverviewTab({ charts }: { charts: readonly StackedBarProps[] }) {
	return (
		<VStack spacing={3}>
			{charts.map((chart) => (
				<StackedBar key={chart.title} {...chart} />
			))}
		</VStack>
	);
}

function GridTab({
	groups,
	clicks,
}: {
	groups: readonly MetricGroupModel[];
	clicks: ReportClickRow[];
}) {
	return (
		<VStack spacing={3}>
			{groups.map((group) => (
				<VStack key={group.id} spacing={1}>
					{group.title ? (
						<p className="prc-email-engagement-section-title">
							{group.title}
						</p>
					) : null}
					<MetricGrid cards={group.cards} />
				</VStack>
			))}
			<ClickedLinksList clicks={clicks} />
		</VStack>
	);
}

export interface EngagementReportProps {
	summary: ReportSummary;
	clicks: ReportClickRow[];
}

export function EngagementReport({ summary, clicks }: EngagementReportProps) {
	const metricGroups = buildMetricGroups(summary, METRIC_GROUPS);
	const charts = buildCharts(summary);

	return (
		<TabPanel
			className="prc-email-engagement-tabs"
			initialTabName="overview"
			tabs={[
				{
					name: 'overview',
					title: __('Overview', 'prc-email-builder'),
				},
				{ name: 'grid', title: __('Grid', 'prc-email-builder') },
			]}
		>
			{({ name }): ReactNode =>
				name === 'grid' ? (
					<GridTab groups={metricGroups} clicks={clicks} />
				) : (
					<OverviewTab charts={charts} />
				)
			}
		</TabPanel>
	);
}

export default EngagementReport;
