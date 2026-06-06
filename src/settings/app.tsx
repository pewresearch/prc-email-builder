import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Spinner,
	Notice,
	__experimentalVStack as VStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
} from '@wordpress/components';

import './style.scss';
import './store';
import { fetchSettings } from './api';
import SettingsAccordion from './components/settings-accordion';
import MailchimpSection from './components/mailchimp-section';
import MandrillSection from './components/mandrill-section';
import type { SettingsAccordionItem } from './types';

const SETTINGS_SECTIONS: SettingsAccordionItem[] = [
	{
		title: __('Mailchimp', 'prc-email-builder'),
		description: __(
			'API connection, sender name, and reply-to email address.',
			'prc-email-builder'
		),
		slug: 'mailchimp',
	},
	{
		title: __('Mandrill Delivery', 'prc-email-builder'),
		description: __(
			'Open/click tracking, tags, reply-to, and subaccount applied to all Mandrill sends (bulk and system emails).',
			'prc-email-builder'
		),
		slug: 'mandrill',
	},
];

function getSectionComponent(slug: string) {
	switch (slug) {
		case 'mailchimp':
			return <MailchimpSection />;
		case 'mandrill':
			return <MandrillSection />;
		default:
			return null;
	}
}

export default function SettingsApp() {
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState<string | null>(null);

	useEffect(() => {
		fetchSettings()
			.then(() => setError(null))
			.catch((e: Error) => setError(e.message))
			.finally(() => setLoading(false));
	}, []);

	return (
		<div className="newsletter-builder-settings">
			{error && (
				<Notice status="error" isDismissible={false}>
					{__('Error loading settings:', 'prc-email-builder')} {error}
				</Notice>
			)}
			<VStack spacing={2} className="newsletter-builder-settings__header">
				<Heading level={1}>
					{__('Newsletter Builder Settings', 'prc-email-builder')}
				</Heading>
				<Text className="newsletter-builder-settings__header-description">
					{__(
						'Configure Mailchimp integration and default sender settings for the Newsletter Builder.',
						'prc-email-builder'
					)}
				</Text>
			</VStack>
			{loading ? (
				<div className="newsletter-builder-settings__loading">
					<Spinner />
				</div>
			) : (
				!error && (
					<VStack
						spacing={4}
						className="newsletter-builder-settings__content"
					>
						<ul
							className="newsletter-builder-settings__list"
							role="list"
						>
							{SETTINGS_SECTIONS.map((section) => (
								<li
									key={section.slug}
									className="newsletter-builder-settings__list-item"
								>
									<SettingsAccordion
										title={section.title}
										description={section.description}
										contentId={`newsletter-builder-settings-${section.slug}`}
										headingId={`newsletter-builder-settings-${section.slug}-heading`}
										descriptionId={`newsletter-builder-settings-${section.slug}-description`}
									>
										{getSectionComponent(section.slug)}
									</SettingsAccordion>
								</li>
							))}
						</ul>
					</VStack>
				)
			)}
		</div>
	);
}
