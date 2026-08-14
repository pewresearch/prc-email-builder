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

export function getEmailConfig(): EmailDataviewConfig | undefined {
	return window.prcWpAdminDataview?.email;
}
