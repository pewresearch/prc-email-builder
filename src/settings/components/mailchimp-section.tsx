import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	TextControl,
	Button,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { store as settingsStore } from '../store';
import { saveSettings } from '../api';
import { ConnectionBadge } from './connection-badge';

export default function MailchimpSection() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);
	const { updateField } = useDispatch(settingsStore);

	const [isSaving, setIsSaving] = useState(false);
	const [draft, setDraft] = useState({
		mailchimp_api_key: settings.mailchimp_api_key,
		from_name: settings.from_name,
		from_email: settings.from_email,
	});

	useEffect(() => {
		setDraft({
			mailchimp_api_key: settings.mailchimp_api_key,
			from_name: settings.from_name,
			from_email: settings.from_email,
		});
	}, [settings]);

	async function handleSave() {
		setIsSaving(true);
		try {
			updateField('from_name', draft.from_name);
			updateField('from_email', draft.from_email);
			if (!settings.api_key_via_constant) {
				updateField('mailchimp_api_key', draft.mailchimp_api_key);
			}
			await saveSettings();
		} finally {
			setIsSaving(false);
		}
	}

	return (
		<VStack spacing={4}>
			<HStack justify="flex-start">
				<ConnectionBadge connected={settings.connected} />
			</HStack>

			{!settings.api_key_via_constant && (
				<TextControl
					__nextHasNoMarginBottom
					label={__('API Key', 'prc-email-builder')}
					type="password"
					value={draft.mailchimp_api_key}
					onChange={(val) =>
						setDraft((d) => ({ ...d, mailchimp_api_key: val }))
					}
					help={__(
						'Found in your Mailchimp account under Profile → Extras → API Keys.',
						'prc-email-builder'
					)}
				/>
			)}

			{settings.api_key_via_constant && (
				<Text size={12} color="#757575">
					{__(
						'API key is set via the PRC_PLATFORM_MAILCHIMP_KEY constant.',
						'prc-email-builder'
					)}
				</Text>
			)}

			<TextControl
				__nextHasNoMarginBottom
				label={__('From Name', 'prc-email-builder')}
				value={draft.from_name}
				onChange={(val) => setDraft((d) => ({ ...d, from_name: val }))}
				placeholder="Pew Research Center"
			/>

			<TextControl
				__nextHasNoMarginBottom
				label={__('From Email', 'prc-email-builder')}
				type="email"
				value={draft.from_email}
				onChange={(val) => setDraft((d) => ({ ...d, from_email: val }))}
				placeholder="newsletters@pewresearch.org"
			/>

			<div className="newsletter-builder-settings__form-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={handleSave}
					isBusy={isSaving}
					disabled={isSaving}
				>
					{__('Save', 'prc-email-builder')}
				</Button>
			</div>
		</VStack>
	);
}
