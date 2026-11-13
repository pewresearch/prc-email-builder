export type PostRecord = {
	meta?: Record<string, unknown>;
	[key: string]: unknown;
};

/**
 * Copy of a saved post record with server meta merged in.
 *
 * @param record Saved post record from core-data.
 * @param patch  Meta values the server reported.
 */
export function withServerMeta(
	record: PostRecord,
	patch: Record<string, string>
): PostRecord {
	return { ...record, meta: { ...record.meta, ...patch } };
}
