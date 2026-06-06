import type { ReactNode } from 'react';

export interface Settings {
	mailchimp_api_key: string;
	from_name: string;
	from_email: string;
	track_opens: boolean;
	track_clicks: boolean;
	reply_to: string;
	mandrill_subaccount: string;
	mandrill_tags: string[];
	connected: boolean;
	api_key_via_constant: boolean;
	mandrill_configured: boolean;
}

export interface ApiResponse {
	settings: Settings;
}

export interface SettingsStoreState {
	settings: Settings;
	isLoaded: boolean;
}

export interface SettingsAccordionProps {
	title: string;
	description: string;
	children: ReactNode;
	contentId?: string;
	headingId?: string;
	descriptionId?: string;
}

export interface SettingsAccordionItem {
	title: string;
	description: string;
	slug: string;
}
