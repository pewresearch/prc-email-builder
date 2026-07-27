/**
 * Newsletter List term edit control for associating a default campaign pattern.
 */

import { __ } from '@wordpress/i18n';
import { createRoot, useCallback, useMemo, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import {
	EmailPatternPickerModal,
	type PatternPickerItem,
} from '../sidebar/pattern-selector';
import { useEmailPatterns } from '../sidebar/pattern-selector/use-email-patterns';

interface TermAdminConfig {
	restNamespace: string;
	nonce: string;
	campaignPatternCategorySlug?: string;
	campaignPatternMetaKey?: string;
}

const config: TermAdminConfig = (
	window as { prcEmailBuilderTermAdmin?: TermAdminConfig }
).prcEmailBuilderTermAdmin ?? {
	restNamespace: 'prc-email-builder/v1',
	nonce: '',
};

const META_INPUT_ID =
	config.campaignPatternMetaKey ?? 'prc_newsletter_list_campaign_pattern';
const CATEGORY_SLUG = config.campaignPatternCategorySlug ?? 'email-campaign';

function getMetaInput(): HTMLInputElement | null {
	return document.getElementById(META_INPUT_ID) as HTMLInputElement | null;
}

function CampaignPatternControl() {
	const [patternName, setPatternName] = useState(
		() => getMetaInput()?.value ?? ''
	);
	const [isModalOpen, setIsModalOpen] = useState(false);

	const { patterns, isLoading, error } = useEmailPatterns(
		CATEGORY_SLUG,
		true
	);

	const selectedPattern = useMemo(
		() => patterns.find((pattern) => pattern.name === patternName) ?? null,
		[patternName, patterns]
	);

	const hasSavedPattern = patternName !== '';
	const isUnavailable =
		hasSavedPattern && !isLoading && !error && !selectedPattern;

	const persistPatternName = useCallback((nextName: string) => {
		setPatternName(nextName);
		const input = getMetaInput();
		if (input) {
			input.value = nextName;
		}
	}, []);

	const handleSelect = useCallback(
		(item: PatternPickerItem) => {
			if (item.name === '__blank__') {
				return;
			}
			persistPatternName(item.name);
			setIsModalOpen(false);
		},
		[persistPatternName]
	);

	return (
		<div className="prc-newsletter-list-campaign-pattern">
			{isLoading ? (
				<div className="prc-newsletter-list-campaign-pattern__status">
					<Spinner />
					<span>{__('Loading patterns…', 'prc-email-builder')}</span>
				</div>
			) : null}

			{error ? (
				<Notice status="warning" isDismissible={false}>
					{error}
				</Notice>
			) : null}

			{!isLoading && !error && selectedPattern ? (
				<p className="prc-newsletter-list-campaign-pattern__selected">
					<strong>
						{__('Selected pattern:', 'prc-email-builder')}
					</strong>{' '}
					{selectedPattern.title}
				</p>
			) : null}

			{isUnavailable ? (
				<Notice status="warning" isDismissible={false}>
					{__(
						'The previously selected pattern is no longer available. Choose a new pattern or clear the association.',
						'prc-email-builder'
					)}
				</Notice>
			) : null}

			{!isLoading && !error && !hasSavedPattern ? (
				<p className="description">
					{__('No pattern assigned.', 'prc-email-builder')}
				</p>
			) : null}

			<div className="prc-newsletter-list-campaign-pattern__actions">
				<Button
					variant="secondary"
					onClick={() => setIsModalOpen(true)}
				>
					{hasSavedPattern
						? __('Change pattern', 'prc-email-builder')
						: __('Choose pattern', 'prc-email-builder')}
				</Button>
				{hasSavedPattern ? (
					<Button
						variant="tertiary"
						isDestructive
						onClick={() => persistPatternName('')}
					>
						{__('Clear pattern', 'prc-email-builder')}
					</Button>
				) : null}
			</div>

			<EmailPatternPickerModal
				isOpen={isModalOpen}
				editorKind="campaign"
				patternCategorySlug={CATEGORY_SLUG}
				onSelect={handleSelect}
				onClose={() => setIsModalOpen(false)}
				dismissLabel={__('Cancel', 'prc-email-builder')}
				includeBlank={false}
				shouldCloseOnClickOutside
				shouldCloseOnEsc
			/>
		</div>
	);
}

export function mountCampaignPatternControl(): void {
	const container = document.getElementById(
		'prc-newsletter-list-campaign-pattern-root'
	);
	if (!container) {
		return;
	}

	const root = createRoot(container);
	root.render(<CampaignPatternControl />);
}
