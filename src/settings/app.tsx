/* eslint-disable @wordpress/i18n-text-domain -- shared TEXT_DOMAIN constant */
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { __experimentalText as Text } from '@wordpress/components';
import {
	ConnectionBadge,
	SettingsPage,
	type SettingsFieldConfig,
} from '@prc/components';

import './style.scss';
import { fetchSettings, saveSettings } from './api';
import { store as settingsStore } from './store';

const TEXT_DOMAIN = 'prc-email-builder' as const;

const TIMEZONE_OPTIONS = [
	{ value: 'America/New_York', label: 'Eastern (America/New_York)' },
	{ value: 'America/Chicago', label: 'Central (America/Chicago)' },
	{ value: 'America/Denver', label: 'Mountain (America/Denver)' },
	{ value: 'America/Los_Angeles', label: 'Pacific (America/Los_Angeles)' },
	{ value: 'UTC', label: 'UTC' },
];

function parseTagsInput(value: string): string[] {
	return value
		.split(/[\s,]+/)
		.map((tag) =>
			tag
				.trim()
				.toLowerCase()
				.replace(/[^a-z0-9_-]/g, '')
		)
		.filter(Boolean);
}

function MailchimpBadge() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);

	return (
		<ConnectionBadge
			connected={settings.connected}
			textDomain={TEXT_DOMAIN}
		/>
	);
}

function MailchimpIntro() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);

	if (!settings.api_key_via_constant) {
		return null;
	}

	return (
		<Text size={12} color="#757575">
			{__(
				'API key is set via the PRC_PLATFORM_MAILCHIMP_KEY constant.',
				TEXT_DOMAIN
			)}
		</Text>
	);
}

function MandrillBadge() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);

	return (
		<ConnectionBadge
			connected={settings.mandrill_configured}
			connectedLabel={__('Configured', TEXT_DOMAIN)}
			disconnectedLabel={__('Not configured', TEXT_DOMAIN)}
			textDomain={TEXT_DOMAIN}
		/>
	);
}

function MandrillIntro() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);

	if (settings.mandrill_configured) {
		return (
			<Text size={12} color="#757575">
				{__(
					'API key is set via the PRC_PLATFORM_MANDRILL_KEY constant. These defaults apply to all Mandrill sends from Email Builder (bulk and system emails). A path-specific tag (bulk or system-email) is added automatically.',
					TEXT_DOMAIN
				)}
			</Text>
		);
	}

	return (
		<Text size={12} color="#d63638">
			{__(
				'Mandrill API key is not set. Define the PRC_PLATFORM_MANDRILL_KEY constant to enable bulk and system-email sends.',
				TEXT_DOMAIN
			)}
		</Text>
	);
}

const mailchimpFields: SettingsFieldConfig[] = [
	{
		id: 'mailchimp_api_key',
		type: 'password',
		label: __('API Key', TEXT_DOMAIN),
		description: __(
			'Found in your Mailchimp account under Profile → Extras → API Keys.',
			TEXT_DOMAIN
		),
		isVisible: (settings) => !settings.api_key_via_constant,
	},
	{
		id: 'from_name',
		type: 'text',
		label: __('From Name', TEXT_DOMAIN),
		placeholder: 'Pew Research Center',
	},
	{
		id: 'from_email',
		type: 'email',
		label: __('From Email', TEXT_DOMAIN),
		placeholder: 'newsletters@pewresearch.org',
	},
	{
		id: 'auto_send_on_publish',
		type: 'boolean',
		label: __('Automatically send on publish', TEXT_DOMAIN),
		description: __(
			'When enabled, publishing or scheduling a campaign creates the Mailchimp campaign and sends it. When disabled, publish only saves the WordPress post. Use Send to Mailchimp in Campaign Setup to deliver.',
			TEXT_DOMAIN
		),
	},
];

const mandrillFields: SettingsFieldConfig[] = [
	{
		id: 'track_opens',
		type: 'boolean',
		label: __('Track opens', TEXT_DOMAIN),
	},
	{
		id: 'track_clicks',
		type: 'boolean',
		label: __('Track clicks', TEXT_DOMAIN),
	},
	{
		id: 'reply_to',
		type: 'email',
		label: __('Reply-To', TEXT_DOMAIN),
		description: __(
			'Leave blank to use the From Email address.',
			TEXT_DOMAIN
		),
	},
	{
		id: 'mandrill_subaccount',
		type: 'text',
		label: __('Subaccount', TEXT_DOMAIN),
		description: __(
			'Optional Mandrill subaccount ID for reporting segmentation.',
			TEXT_DOMAIN
		),
	},
	{
		id: 'mandrill_tags',
		type: 'text',
		label: __('Base tags', TEXT_DOMAIN),
		description: __(
			'Comma-separated tags applied to every send. bulk or system-email is appended per path.',
			TEXT_DOMAIN
		),
		format: (value) => (Array.isArray(value) ? value.join(', ') : ''),
		parse: (value) => {
			const parsedTags = parseTagsInput(String(value ?? ''));
			return parsedTags.length > 0 ? parsedTags : ['prc-newsletter'];
		},
	},
];

const automationFields: SettingsFieldConfig[] = [
	{
		id: 'automation_default_send_window.timezone',
		type: 'select',
		label: __('Timezone', TEXT_DOMAIN),
		options: TIMEZONE_OPTIONS,
	},
	{
		id: 'automation_default_send_window.hour',
		type: 'integer',
		label: __('Hour (0–23)', TEXT_DOMAIN),
		min: 0,
		max: 23,
	},
	{
		id: 'automation_default_send_window.minute',
		type: 'integer',
		label: __('Minute (0–59)', TEXT_DOMAIN),
		min: 0,
		max: 59,
	},
];

export default function SettingsApp() {
	return (
		<SettingsPage
			title={__('Email Builder Settings', TEXT_DOMAIN)}
			description={__(
				'Configure Mailchimp integration and default sender settings for the Email Builder.',
				TEXT_DOMAIN
			)}
			textDomain={TEXT_DOMAIN}
			idPrefix="prc-email-builder-settings"
			store={settingsStore}
			saveSettings={saveSettings}
			sections={[
				{
					slug: 'mailchimp',
					title: __('Mailchimp', TEXT_DOMAIN),
					description: __(
						'API connection, sender name, and reply-to email address.',
						TEXT_DOMAIN
					),
					badge: () => <MailchimpBadge />,
					intro: () => <MailchimpIntro />,
					fields: mailchimpFields,
				},
				{
					slug: 'mandrill',
					title: __('Mandrill Delivery', TEXT_DOMAIN),
					description: __(
						'Open/click tracking, tags, reply-to, and subaccount applied to all Mandrill sends (bulk and system emails).',
						TEXT_DOMAIN
					),
					badge: () => <MandrillBadge />,
					intro: () => <MandrillIntro />,
					fields: mandrillFields,
				},
				{
					slug: 'automations',
					title: __('Automations', TEXT_DOMAIN),
					description: __(
						'Default send window for scheduled follow-up (drip) emails on dynamic system emails.',
						TEXT_DOMAIN
					),
					intro: () => (
						<Text size={12} color="#757575">
							{__(
								'The default time of day scheduled follow-up emails are sent. Individual automations and steps can override this window.',
								TEXT_DOMAIN
							)}
						</Text>
					),
					fields: automationFields,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
