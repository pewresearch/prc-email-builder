/**
 * Input model for adding or editing a Mailchimp newsletter list.
 */

export interface NewsletterListInput {
	readonly name: string;
	readonly description: string;
	readonly audienceId: string;
	readonly segmentId: string;
	readonly fromName: string;
	readonly fromEmail: string;
	readonly campaignPattern: string;
}

export const INITIAL_NEWSLETTER_LIST: NewsletterListInput = {
	name: '',
	description: '',
	audienceId: '',
	segmentId: '',
	fromName: '',
	fromEmail: '',
	campaignPattern: '',
};

/**
 * Subset of a core `/wp/v2/prc_newsletter_list/{id}?context=edit` response.
 */
export interface NewsletterListTerm {
	readonly name?: string;
	readonly description?: string;
	readonly meta?: Record<string, unknown>;
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function isValidOptionalEmail(email: string): boolean {
	const trimmed = email.trim();
	return trimmed === '' || EMAIL_PATTERN.test(trimmed);
}

export function canCreateNewsletterList(input: NewsletterListInput): boolean {
	return (
		input.name.trim() !== '' &&
		input.audienceId !== '' &&
		isValidOptionalEmail(input.fromEmail)
	);
}

function metaString(meta: Record<string, unknown>, key: string): string {
	const value = meta[key];
	return typeof value === 'string' ? value : '';
}

/**
 * Form input from a saved term. `decode` turns stored HTML entities back into text.
 *
 * @param term   Term from the core REST route.
 * @param decode Entity decoder.
 */
export function fromNewsletterListTerm(
	term: NewsletterListTerm,
	decode: (value: string) => string = (value) => value
): NewsletterListInput {
	const meta = term.meta ?? {};
	return {
		name: decode(term.name ?? ''),
		description: decode(term.description ?? ''),
		audienceId: metaString(meta, 'prc_newsletter_list_audience_id'),
		segmentId: metaString(meta, 'prc_newsletter_list_segment_id'),
		fromName: metaString(meta, 'prc_newsletter_list_from_name'),
		fromEmail: metaString(meta, 'prc_newsletter_list_from_email'),
		campaignPattern: metaString(
			meta,
			'prc_newsletter_list_campaign_pattern'
		),
	};
}

/**
 * Core term REST body. A segment only applies inside its audience.
 *
 * @param input Form input.
 */
export function toNewsletterListPayload(input: NewsletterListInput): {
	name: string;
	description: string;
	meta: Record<string, string>;
} {
	return {
		name: input.name.trim(),
		description: input.description.trim(),
		meta: {
			prc_newsletter_list_audience_id: input.audienceId,
			prc_newsletter_list_segment_id:
				input.audienceId === '' ? '' : input.segmentId,
			prc_newsletter_list_from_name: input.fromName.trim(),
			prc_newsletter_list_from_email: input.fromEmail.trim(),
			prc_newsletter_list_campaign_pattern: input.campaignPattern,
		},
	};
}
