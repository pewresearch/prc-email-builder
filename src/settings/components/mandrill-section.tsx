import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import {
	TextControl,
	Button,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
	__experimentalText as Text,
} from '@wordpress/components';
import { store as settingsStore } from '../store';
import { saveSettings } from '../api';
import type { Settings } from '../types';
import { ConnectionBadge } from './connection-badge';

function tagsToString(tags: string[]): string {
	return tags.join(', ');
}

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

type MandrillDraft = Pick<
	Settings,
	| 'track_opens'
	| 'track_clicks'
	| 'reply_to'
	| 'mandrill_subaccount'
	| 'mandrill_tags'
>;

export default function MandrillSection() {
	const settings = useSelect((sel) => sel(settingsStore).getSettings(), []);
	const { updateField } = useDispatch(settingsStore);

	const [isSaving, setIsSaving] = useState(false);
	const [draft, setDraft] = useState<MandrillDraft>({
		track_opens: settings.track_opens,
		track_clicks: settings.track_clicks,
		reply_to: settings.reply_to,
		mandrill_subaccount: settings.mandrill_subaccount,
		mandrill_tags: settings.mandrill_tags,
	});
	const [tagsInput, setTagsInput] = useState(
		tagsToString(settings.mandrill_tags)
	);

	useEffect(() => {
		setDraft({
			track_opens: settings.track_opens,
			track_clicks: settings.track_clicks,
			reply_to: settings.reply_to,
			mandrill_subaccount: settings.mandrill_subaccount,
			mandrill_tags: settings.mandrill_tags,
		});
		setTagsInput(tagsToString(settings.mandrill_tags));
	}, [settings]);

	async function handleSave() {
		setIsSaving(true);
		try {
			const parsedTags = parseTagsInput(tagsInput);
			const tags =
				parsedTags.length > 0 ? parsedTags : ['prc-newsletter'];

			updateField('track_opens', draft.track_opens);
			updateField('track_clicks', draft.track_clicks);
			updateField('reply_to', draft.reply_to);
			updateField('mandrill_subaccount', draft.mandrill_subaccount);
			updateField('mandrill_tags', tags);

			await saveSettings();
		} finally {
			setIsSaving(false);
		}
	}

	return (
		<VStack spacing={4}>
			<HStack justify="flex-start">
				<ConnectionBadge
					connected={settings.mandrill_configured}
					connectedLabel={__('Configured', 'prc-email-builder')}
					disconnectedLabel={__(
						'Not configured',
						'prc-email-builder'
					)}
				/>
			</HStack>

			{settings.mandrill_configured ? (
				<Text size={12} color="#757575">
					{__(
						'API key is set via the PRC_PLATFORM_MANDRILL_KEY constant.',
						'prc-email-builder'
					)}
				</Text>
			) : (
				<Text size={12} color="#d63638">
					{__(
						'Mandrill API key is not set. Define the PRC_PLATFORM_MANDRILL_KEY constant to enable bulk and system-email sends.',
						'prc-email-builder'
					)}
				</Text>
			)}

			<Text size={12} color="#757575">
				{__(
					'These defaults apply to all Mandrill sends from Newsletter Builder (bulk and system emails). A path-specific tag (bulk or system-email) is added automatically.',
					'prc-email-builder'
				)}
			</Text>

			<ToggleControl
				__nextHasNoMarginBottom
				label={__('Track opens', 'prc-email-builder')}
				checked={draft.track_opens}
				onChange={(val) =>
					setDraft((d) => ({ ...d, track_opens: val }))
				}
			/>

			<ToggleControl
				__nextHasNoMarginBottom
				label={__('Track clicks', 'prc-email-builder')}
				checked={draft.track_clicks}
				onChange={(val) =>
					setDraft((d) => ({ ...d, track_clicks: val }))
				}
			/>

			<TextControl
				__nextHasNoMarginBottom
				label={__('Reply-To', 'prc-email-builder')}
				type="email"
				value={draft.reply_to}
				onChange={(val) => setDraft((d) => ({ ...d, reply_to: val }))}
				help={__(
					'Leave blank to use the From Email address.',
					'prc-email-builder'
				)}
			/>

			<TextControl
				__nextHasNoMarginBottom
				label={__('Subaccount', 'prc-email-builder')}
				value={draft.mandrill_subaccount}
				onChange={(val) =>
					setDraft((d) => ({ ...d, mandrill_subaccount: val }))
				}
				help={__(
					'Optional Mandrill subaccount ID for reporting segmentation.',
					'prc-email-builder'
				)}
			/>

			<TextControl
				__nextHasNoMarginBottom
				label={__('Base tags', 'prc-email-builder')}
				value={tagsInput}
				onChange={setTagsInput}
				help={__(
					'Comma-separated tags applied to every send. bulk or system-email is appended per path.',
					'prc-email-builder'
				)}
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
