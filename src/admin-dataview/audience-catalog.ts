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

export interface AudienceCatalogRow {
	readonly key: string;
	readonly label: string;
	readonly count: number;
	readonly dataset_id?: number | null;
	readonly built_at?: string | null;
	readonly builder?: string | null;
	readonly verification?: string | null;
	readonly source_id?: number | null;
	readonly source_title?: string | null;
}

export interface AudienceCatalogGroup {
	readonly slug: string;
	readonly label: string;
	readonly rows: AudienceCatalogRow[];
}

export function groupCatalogRows(
	rows: AudienceCatalogRow[],
	builders: AudienceBuilder[]
): AudienceCatalogGroup[] {
	const groups = builders.map((builder) => ({
		slug: builder.slug,
		label: builder.label,
		rows: [] as AudienceCatalogRow[],
	}));
	const bySlug = Object.fromEntries(
		groups.map((group) => [group.slug, group])
	);
	const other: AudienceCatalogGroup = {
		slug: 'other',
		label: 'Other',
		rows: [],
	};

	for (const row of rows) {
		const group =
			row.builder && bySlug[row.builder] ? bySlug[row.builder] : other;
		group.rows.push(row);
	}

	return [
		...groups.filter((group) => group.rows.length > 0),
		...(other.rows.length > 0 ? [other] : []),
	];
}

export function shouldPollJob(phase: string | undefined): boolean {
	return phase === 'queued' || phase === 'scanning';
}
