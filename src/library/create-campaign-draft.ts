/**
 * Create campaign drafts, optionally assigned to a newsletter list.
 */

import apiFetch from '@wordpress/api-fetch';

import { fetchEmailPatterns } from '../sidebar/pattern-selector/use-email-patterns';
import { getEmailConfig } from './types';

/**
 * Insert a draft campaign and return its editor URL.
 *
 * @param data Extra REST fields (content, taxonomy term IDs).
 */
export async function createCampaignDraft(
	data: Record<string, unknown>
): Promise<string> {
	const config = getEmailConfig();
	const postType = config?.campaignPostType ?? 'prc_email_campaign';
	const post = await apiFetch<{ id: number }>({
		path: `/wp/v2/${postType}`,
		method: 'POST',
		data: {
			status: 'draft',
			...data,
		},
	});

	return `${config?.postEditUrl || 'post.php'}?post=${post.id}&action=edit`;
}

/**
 * Block markup for a named campaign pattern, or '' when unset or unavailable.
 *
 * An empty draft opens the editor's pattern selector, so a missing pattern
 * still produces a usable campaign.
 *
 * @param patternName Pattern name saved on the newsletter list.
 */
export async function resolveCampaignPatternContent(
	patternName: string
): Promise<string> {
	if (!patternName) {
		return '';
	}
	const config = getEmailConfig();
	const patterns = await fetchEmailPatterns(
		config?.campaignPatternCategorySlug ?? 'email-campaign'
	);

	return (
		patterns.find((pattern) => pattern.name === patternName)?.content ?? ''
	);
}

/**
 * Create a campaign draft assigned to a newsletter list and return its editor URL.
 *
 * @param termId      Newsletter list term ID.
 * @param patternName The list's default campaign pattern name.
 */
export async function createCampaignForList(
	termId: number,
	patternName: string
): Promise<string> {
	const taxonomy =
		getEmailConfig()?.newsletterListTaxonomy ?? 'prc_newsletter_list';
	const content = await resolveCampaignPatternContent(patternName);

	return createCampaignDraft({ content, [taxonomy]: [termId] });
}
