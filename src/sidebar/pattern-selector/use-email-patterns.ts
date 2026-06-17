/**
 * Load email patterns from registered block patterns and site-editor wp_block posts.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export interface EmailPatternItem {
	name: string;
	title: string;
	description?: string;
	content: string;
}

interface BlockPatternRecord {
	name: string;
	title: string;
	description?: string;
	content?: string;
	categories?: string[];
}

interface WpBlockRecord {
	id: number;
	title?: { rendered?: string };
	content?: { raw?: string };
	wp_pattern_category?: number[];
}

interface PatternCategoryRecord {
	id: number;
	slug: string;
}

const CAMPAIGN_CATEGORY_SLUG = 'email-campaign';
const LEGACY_CAMPAIGN_CATEGORIES = ['email', 'prc-newsletter'];

function getCategoryAliases(categorySlug: string): string[] {
	if (categorySlug === CAMPAIGN_CATEGORY_SLUG) {
		return [categorySlug, ...LEGACY_CAMPAIGN_CATEGORIES];
	}

	return [categorySlug];
}

function matchesCategory(
	categories: string[] | undefined,
	categorySlug: string
): boolean {
	if (!categories?.length) {
		return false;
	}

	const aliases = getCategoryAliases(categorySlug);
	return categories.some((category) => aliases.includes(category));
}

function mapRegisteredPattern(
	pattern: BlockPatternRecord
): EmailPatternItem | null {
	if (!pattern.content) {
		return null;
	}

	return {
		name: pattern.name,
		title: pattern.title,
		description: pattern.description,
		content: pattern.content,
	};
}

function mapWpBlockPattern(
	block: WpBlockRecord,
	idToSlug: Record<number, string>,
	categorySlug: string
): EmailPatternItem | null {
	const termSlugs = (block.wp_pattern_category ?? [])
		.map((termId) => idToSlug[termId])
		.filter(Boolean);

	const aliases = getCategoryAliases(categorySlug);
	if (!termSlugs.some((slug) => aliases.includes(slug))) {
		return null;
	}

	const content = block.content?.raw ?? '';
	if (!content.trim()) {
		return null;
	}

	return {
		name: `wp-block-${block.id}`,
		title: block.title?.rendered ?? '',
		content,
	};
}

function dedupePatterns(patterns: EmailPatternItem[]): EmailPatternItem[] {
	const seen = new Set<string>();
	return patterns.filter((pattern) => {
		const key = `${pattern.title}::${pattern.content}`;
		if (seen.has(key)) {
			return false;
		}
		seen.add(key);
		return true;
	});
}

async function fetchAllWpBlocks(): Promise<WpBlockRecord[]> {
	const perPage = 100;
	const response = await apiFetch({
		path: `/wp/v2/blocks?per_page=${perPage}&context=edit&page=1`,
		parse: false,
	});

	const blocks = (await response.json()) as WpBlockRecord[];
	const totalPages = Math.min(
		parseInt(response.headers.get('X-WP-TotalPages') || '1', 10) || 1,
		20
	);

	if (totalPages <= 1) {
		return blocks;
	}

	const restPages = await Promise.all(
		Array.from({ length: totalPages - 1 }, (_, index) =>
			apiFetch({
				path: `/wp/v2/blocks?per_page=${perPage}&context=edit&page=${index + 2}`,
			}).then((page) => page as WpBlockRecord[])
		)
	);

	return [blocks, ...restPages].flat();
}

export function useEmailPatterns(categorySlug: string, enabled: boolean) {
	const [patterns, setPatterns] = useState<EmailPatternItem[]>([]);
	const [isLoading, setIsLoading] = useState(enabled);
	const [error, setError] = useState<string | null>(null);
	const prevEnabledRef = useRef(enabled);

	if (enabled !== prevEnabledRef.current) {
		prevEnabledRef.current = enabled;
		if (enabled) {
			setIsLoading(true);
		}
	}

	useEffect(() => {
		if (!enabled || !categorySlug) {
			return undefined;
		}

		let cancelled = false;

		async function loadPatterns() {
			setIsLoading(true);
			setError(null);

			try {
				const [registeredPatterns, wpBlocks, categories] =
					await Promise.all([
						apiFetch({
							path: '/wp/v2/block-patterns/patterns',
						}) as Promise<BlockPatternRecord[]>,
						fetchAllWpBlocks(),
						apiFetch({
							path: '/wp/v2/wp_pattern_category?per_page=100',
						}) as Promise<PatternCategoryRecord[]>,
					]);

				if (cancelled) {
					return;
				}

				const idToSlug: Record<number, string> = {};
				categories.forEach((term) => {
					idToSlug[term.id] = term.slug;
				});

				const fromRegistered = registeredPatterns
					.filter((pattern) =>
						matchesCategory(pattern.categories, categorySlug)
					)
					.map(mapRegisteredPattern)
					.filter(Boolean) as EmailPatternItem[];

				const fromSiteEditor = wpBlocks
					.map((block) =>
						mapWpBlockPattern(block, idToSlug, categorySlug)
					)
					.filter(Boolean) as EmailPatternItem[];

				setPatterns(
					dedupePatterns([...fromRegistered, ...fromSiteEditor]).sort(
						(a, b) => a.title.localeCompare(b.title)
					)
				);
			} catch (err) {
				if (cancelled) {
					return;
				}

				setError(
					err instanceof Error
						? err.message
						: 'Failed to load email patterns.'
				);
				setPatterns([]);
			} finally {
				if (!cancelled) {
					setIsLoading(false);
				}
			}
		}

		loadPatterns();

		return () => {
			cancelled = true;
		};
	}, [categorySlug, enabled]);

	return { patterns, isLoading, error, categorySlug };
}
