import type { ReportSummary } from './use-report';

export type EngagementSegmentId = 'clicked' | 'opened-no-click' | 'not-opened';
export type DeliverySegmentId =
	| 'hard-bounces'
	| 'soft-bounces'
	| 'unsubscribes'
	| 'abuse-reports';
export type ChartSegmentId = EngagementSegmentId | DeliverySegmentId;

export interface NumericSegment<TId extends string = string> {
	id: TId;
	value: number;
	/** 0–1; 0 when denominator is 0 */
	fraction: number;
}

function toFiniteNonNegative(value: number | undefined): number {
	if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
		return 0;
	}
	return value;
}

function withFractions<TId extends string>(
	segments: readonly Omit<NumericSegment<TId>, 'fraction'>[]
): readonly NumericSegment<TId>[] {
	const total = segments.reduce((sum, segment) => sum + segment.value, 0);
	return segments.map((segment) => ({
		...segment,
		fraction: total > 0 ? segment.value / total : 0,
	}));
}

// Caps nested unique counts so clicked ⊆ opened ⊆ sent when hierarchy is messy.
export function buildEngagementSegments(
	summary: ReportSummary
): readonly NumericSegment<EngagementSegmentId>[] {
	const sent = toFiniteNonNegative(summary.emails_sent);
	const opened = Math.min(toFiniteNonNegative(summary.opens_unique), sent);
	const clicked = Math.min(
		toFiniteNonNegative(summary.clicks_unique),
		opened
	);

	return withFractions<EngagementSegmentId>([
		{ id: 'clicked', value: clicked },
		{ id: 'opened-no-click', value: opened - clicked },
		{ id: 'not-opened', value: sent - opened },
	]);
}

// Stack against the four-issue total; emails_sent would hide ~0.5% slices.
export function buildDeliverySegments(
	summary: ReportSummary
): readonly NumericSegment<DeliverySegmentId>[] {
	return withFractions<DeliverySegmentId>([
		{
			id: 'hard-bounces',
			value: toFiniteNonNegative(summary.bounces_hard),
		},
		{
			id: 'soft-bounces',
			value: toFiniteNonNegative(summary.bounces_soft),
		},
		{
			id: 'unsubscribes',
			value: toFiniteNonNegative(summary.unsubscribes),
		},
		{
			id: 'abuse-reports',
			value: toFiniteNonNegative(summary.abuse_reports),
		},
	]);
}
