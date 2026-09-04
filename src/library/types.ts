import { decodeEntities } from '@wordpress/html-entities';
import type { AudienceBuilder } from '../admin-dataview/audience-catalog';

export type EmailListScope = 'campaign' | 'txn';

export interface EmailLibraryRow {
	id: number;
	type: EmailListScope;
	title: string;
	status: string;
	date: string;
	modified: string;
	edit_url: string;
	view_url?: string;
	newsletter_lists: Array<{ slug: string; label: string }>;
	subject: string;
	mailchimp_status: string;
	mandrill_status: string;
	delivery_mode: string;
	open_rate: number | null;
	click_rate: number | null;
	report_sync_state?: string;
	stats_available?: boolean;
	channel?: string;
}

export interface EmailDataviewConfig {
	postEditUrl: string;
	campaignNewUrl?: string;
	transactionalNewUrl?: string;
	postTypeScope: EmailListScope;
	newsletterLists?: Array<{
		termId: number;
		slug: string;
		label: string;
		campaignPattern: string;
	}>;
	sendStatuses?: Array<{ value: string; label: string }>;
	researchTeams?: Array<{
		termId: number;
		slug: string;
		label: string;
	}>;
	campaignPostType?: string;
	transactionalPostType?: string;
	newsletterListTaxonomy?: string;
	campaignPatternCategorySlug?: string;
	audienceBuilders?: AudienceBuilder[];
}

declare global {
	interface Window {
		prcWpAdminDataview?: {
			postType?: string;
			config?: {
				postTypeScope?: EmailListScope;
			};
			email?: EmailDataviewConfig;
		};
	}
}

function decodeLabeledEntries<T extends { label?: string }>(
	items?: T[]
): T[] | undefined {
	if (!items) {
		return items;
	}
	return items.map((item) =>
		typeof item.label === 'string'
			? { ...item, label: decodeEntities(item.label) }
			: item
	);
}

/**
 * Decode user-authored strings from PHP boot data for React text nodes.
 *
 * @param {EmailDataviewConfig} [config] Localized email DataViews boot object.
 * @return {EmailDataviewConfig | undefined} Config with decoded labels.
 */
export function decodeEmailConfig(
	config: EmailDataviewConfig | undefined
): EmailDataviewConfig | undefined {
	if (!config) {
		return config;
	}
	return {
		...config,
		newsletterLists: decodeLabeledEntries(config.newsletterLists),
		researchTeams: decodeLabeledEntries(config.researchTeams),
		sendStatuses: decodeLabeledEntries(config.sendStatuses),
	};
}

export function getEmailConfig(): EmailDataviewConfig | undefined {
	return decodeEmailConfig(window.prcWpAdminDataview?.email);
}
