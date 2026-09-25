export type AudienceBuilderForm =
	| 'domain-query'
	| 'source-entity'
	| 'csv-upload';

export interface AudienceBuilder {
	readonly slug: string;
	readonly label: string;
	readonly description: string;
	readonly form: AudienceBuilderForm;
	readonly jobIdPrefix: string;
	readonly supportsCreateDraft: boolean;
	readonly sourcePostType: string | null;
	readonly sourceIdParam: string | null;
}

export interface AudienceAnalytics {
	readonly sentCount: number;
	readonly avgOpenRate: number | null;
	readonly avgClickRate: number | null;
	readonly lastSentAt: string | null;
}

interface AudienceListRowBase {
	readonly id: string;
	readonly title: string;
	readonly count: number | null;
	readonly builder: string | null;
	readonly builderLabel: string;
	readonly verification: 'verified' | 'unverified' | 'all' | null;
	readonly sourceId: number | null;
	readonly sourceTitle: string | null;
	readonly builtAt: string | null;
	readonly datasetId: number | null;
	readonly analytics: AudienceAnalytics;
}

export interface StoredAudienceRow extends AudienceListRowBase {
	readonly recordType: 'audience';
	readonly count: number;
	readonly status: 'ready';
	readonly jobId: null;
	readonly requestedAt: null;
	readonly scannedUsers: null;
	readonly matchedUsers: null;
	readonly referenceCount: number;
}

export interface ActiveAudienceJobRow extends AudienceListRowBase {
	readonly recordType: 'job';
	readonly status: 'queued' | 'scanning';
	readonly jobId: string;
	readonly requestedAt: string | null;
	readonly scannedUsers: number | null;
	readonly matchedUsers: number | null;
	readonly referenceCount: 0;
}

export interface NewsletterListRow extends AudienceListRowBase {
	readonly recordType: 'newsletter-list';
	readonly id: `list:${number}`;
	readonly termId: number;
	readonly status: 'ready';
	readonly countSyncedAt: string | null;
	readonly mailchimpAudienceId: string | null;
	readonly mailchimpAudienceName: string | null;
	readonly segmentName: string | null;
	readonly campaignPattern: string;
	readonly editUrl: string | null;
	readonly jobId: null;
	readonly requestedAt: null;
	readonly scannedUsers: null;
	readonly matchedUsers: null;
	readonly referenceCount: 0;
}

export type AudienceListRow =
	| StoredAudienceRow
	| ActiveAudienceJobRow
	| NewsletterListRow;

export type AudienceRecordType = AudienceListRow['recordType'];

export const OTHER_BUILDER = 'other';
export const MAILCHIMP_BUILDER = 'mailchimp';

export function isStoredAudience(
	item: AudienceListRow
): item is StoredAudienceRow {
	return item.recordType === 'audience';
}

export function isNewsletterList(
	item: AudienceListRow
): item is NewsletterListRow {
	return item.recordType === 'newsletter-list';
}

/**
 * Rate between 0 and 1 as a whole-or-one-decimal percentage, or an em dash.
 *
 * @param rate Stored rate.
 */
export function formatRate(rate: number | null): string {
	if (rate === null || !Number.isFinite(rate)) {
		return '—';
	}
	const percent = Math.round(rate * 1000) / 10;
	return `${percent}%`;
}

/**
 * Mailchimp targeting label for a newsletter list row.
 *
 * @param item Newsletter list row.
 */
export function getMailchimpTargetLabel(item: NewsletterListRow): string {
	const audience = item.mailchimpAudienceName ?? item.mailchimpAudienceId;
	if (!audience) {
		return item.builderLabel;
	}
	const target = item.segmentName
		? `${audience} / ${item.segmentName}`
		: audience;
	return `${item.builderLabel}: ${target}`;
}

const AUDIENCE_ORDERBY = new Set(['title', 'count', 'builtAt']);

export function getBuilderFilterElements(
	builders: readonly AudienceBuilder[],
	otherLabel: string
): Array<{ value: string; label: string }> {
	return [
		...builders.map((builder) => ({
			value: builder.slug,
			label: builder.label,
		})),
		{ value: OTHER_BUILDER, label: otherLabel },
	];
}

/**
 * Map shell list args to `/audiences-system/library` query args.
 *
 * @param args      Args from the shell's viewToQueryArgs.
 * @param sortField DataViews sort field id.
 * @return Query args the audience library route accepts.
 */
export function mapAudienceQuery(
	args: Record<string, unknown>,
	sortField: string | undefined
): Record<string, unknown> {
	const mapped: Record<string, unknown> = {
		per_page: args.per_page,
		page: args.page,
		order: args.order === 'asc' ? 'asc' : 'desc',
		orderby:
			sortField && AUDIENCE_ORDERBY.has(sortField)
				? sortField
				: 'builtAt',
	};
	if (typeof args.search === 'string' && args.search !== '') {
		mapped.search = args.search;
	}
	if (typeof args.recordType === 'string' && args.recordType !== '') {
		mapped.type = args.recordType;
	}
	if (typeof args.builder === 'string' && args.builder !== '') {
		mapped.builder = args.builder;
	}
	return mapped;
}

export function shouldPollJob(phase: string | undefined): boolean {
	return phase === 'queued' || phase === 'scanning';
}
