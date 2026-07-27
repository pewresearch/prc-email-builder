/**
 * Newsletter Lists taxonomy admin: load Mailchimp segments when audience changes.
 */

import apiFetch from '@wordpress/api-fetch';
import { registerCoreBlocks } from '@wordpress/block-library';
import { getBlockType } from '@wordpress/blocks';
import { mountCampaignPatternControl } from './campaign-pattern-control';
import './style.scss';

interface Segment {
	id: number;
	name: string;
	member_count: number;
}

interface SubscriberCountResponse {
	count: number;
	scope: 'segment' | 'audience';
}

interface TermAdminConfig {
	restNamespace: string;
	nonce: string;
}

const config: TermAdminConfig = (
	window as { prcEmailBuilderTermAdmin?: TermAdminConfig }
).prcEmailBuilderTermAdmin ?? {
	restNamespace: 'prc-email-builder/v1',
	nonce: '',
};

function getAudienceSelect(): HTMLSelectElement | null {
	return document.getElementById(
		'prc_newsletter_list_audience_id'
	) as HTMLSelectElement | null;
}

function getSegmentSelect(): HTMLSelectElement | null {
	return document.getElementById(
		'prc_newsletter_list_segment_id'
	) as HTMLSelectElement | null;
}

function getSegmentSpinner(): HTMLElement | null {
	return document.getElementById('prc_newsletter_list_segment_spinner');
}

function getSubscriberCountElement(): HTMLElement | null {
	return document.getElementById('prc_newsletter_list_subscriber_count');
}

function setSubscriberCountDisplay(
	count: number | null,
	scope: 'segment' | 'audience'
): void {
	const el = getSubscriberCountElement();
	if (!el) {
		return;
	}

	el.dataset.scope = scope;
	el.textContent = count === null ? '…' : count.toLocaleString();
}

async function fetchAudienceSubscriberCount(audienceId: string): Promise<void> {
	if (!audienceId) {
		setSubscriberCountDisplay(null, 'audience');
		const el = getSubscriberCountElement();
		if (el) {
			el.textContent = '—';
		}
		return;
	}

	setSubscriberCountDisplay(null, 'audience');

	try {
		const response = await apiFetch<SubscriberCountResponse>({
			path: `/${config.restNamespace}/audiences/${encodeURIComponent(audienceId)}/count`,
		});
		setSubscriberCountDisplay(response.count, response.scope);
	} catch {
		const el = getSubscriberCountElement();
		if (el) {
			el.textContent = '—';
		}
	}
}

function updateSubscriberCountFromSegmentSelection(
	segments: Segment[],
	segmentId: string
): void {
	if (!segmentId) {
		const audienceSelect = getAudienceSelect();
		if (audienceSelect?.value) {
			void fetchAudienceSubscriberCount(audienceSelect.value);
		}
		return;
	}

	const segment = segments.find((item) => String(item.id) === segmentId);
	if (segment) {
		setSubscriberCountDisplay(segment.member_count, 'segment');
		return;
	}

	const audienceSelect = getAudienceSelect();
	if (audienceSelect?.value) {
		void fetchAudienceSubscriberCount(audienceSelect.value);
	}
}

function clearSelectOptions(select: HTMLSelectElement): void {
	while (select.options.length > 0) {
		select.remove(0);
	}
}

function formatSegmentLabel(segment: Segment): string {
	return `${segment.name} (${segment.member_count.toLocaleString()})`;
}

function setSpinnerActive(active: boolean): void {
	const spinner = getSegmentSpinner();
	if (!spinner) {
		return;
	}
	if (active) {
		spinner.classList.add('is-active');
	} else {
		spinner.classList.remove('is-active');
	}
}

function showNoAudienceState(segmentSelect: HTMLSelectElement): void {
	clearSelectOptions(segmentSelect);
	const option = new Option('Select an audience first', '');
	option.disabled = true;
	option.selected = true;
	segmentSelect.add(option);
	segmentSelect.disabled = true;
	setSpinnerActive(false);
}

function showLoadingState(
	segmentSelect: HTMLSelectElement,
	submittedValue: string
): void {
	clearSelectOptions(segmentSelect);
	const loadingOption = new Option('Loading segments…', submittedValue);
	loadingOption.selected = true;
	segmentSelect.add(loadingOption);
	setSpinnerActive(true);
}

function populateSegments(
	segmentSelect: HTMLSelectElement,
	segments: Segment[],
	preserveSegmentId = ''
): void {
	clearSelectOptions(segmentSelect);

	const entireOption = new Option('Entire audience', '');
	entireOption.selected = preserveSegmentId === '';
	segmentSelect.add(entireOption);

	let preservedMatch = false;
	for (const segment of segments) {
		const option = new Option(
			formatSegmentLabel(segment),
			String(segment.id)
		);
		if (preserveSegmentId && preserveSegmentId === option.value) {
			option.selected = true;
			entireOption.selected = false;
			preservedMatch = true;
		}
		segmentSelect.add(option);
	}

	if (preserveSegmentId && !preservedMatch) {
		const fallbackOption = new Option(
			`Saved segment (${preserveSegmentId})`,
			preserveSegmentId
		);
		fallbackOption.selected = true;
		entireOption.selected = false;
		segmentSelect.add(fallbackOption);
	}

	segmentSelect.disabled = false;
	setSpinnerActive(false);

	const audienceSelect = getAudienceSelect();
	if (audienceSelect?.value) {
		updateSubscriberCountFromSegmentSelection(
			segments,
			segmentSelect.value
		);
	}
}

function showSegmentLoadError(
	segmentSelect: HTMLSelectElement,
	preserveSegmentId: string
): void {
	clearSelectOptions(segmentSelect);

	const entireOption = new Option('Entire audience', '');
	segmentSelect.add(entireOption);

	if (preserveSegmentId) {
		const preservedOption = new Option(
			`Could not load segments — keeping saved (${preserveSegmentId})`,
			preserveSegmentId
		);
		preservedOption.selected = true;
		segmentSelect.add(preservedOption);
	} else {
		entireOption.selected = true;
	}

	segmentSelect.disabled = false;
	setSpinnerActive(false);
}

async function loadSegments(
	audienceId: string,
	preserveSegmentId = ''
): Promise<void> {
	const segmentSelect = getSegmentSelect();
	if (!segmentSelect) {
		return;
	}

	if (!audienceId) {
		showNoAudienceState(segmentSelect);
		const el = getSubscriberCountElement();
		if (el) {
			el.textContent = '—';
			el.dataset.scope = 'audience';
		}
		return;
	}

	showLoadingState(segmentSelect, preserveSegmentId);

	try {
		const segments = await apiFetch<Segment[]>({
			path: `/${config.restNamespace}/audiences/${encodeURIComponent(audienceId)}/segments`,
		});
		populateSegments(segmentSelect, segments, preserveSegmentId);
	} catch {
		showSegmentLoadError(segmentSelect, preserveSegmentId);
		if (!preserveSegmentId) {
			void fetchAudienceSubscriberCount(audienceId);
		}
	}
}

function initAccentColorPicker(): void {
	const input = document.getElementById(
		'prc_newsletter_list_accent_color'
	) as HTMLInputElement | null;
	// wp-color-picker is enqueued as a jQuery plugin on this admin screen.
	const jq = (
		window as unknown as {
			jQuery?: (el: HTMLElement) => {
				wpColorPicker: (opts?: {
					defaultColor?: boolean | string;
					clear?: () => void;
				}) => void;
			};
		}
	).jQuery;

	if (!input || typeof jq !== 'function') {
		return;
	}

	jq(input).wpColorPicker({
		defaultColor: false,
		clear() {
			input.value = '';
		},
	});
}

function init(): void {
	// term.php has no block editor, so nothing registers block types in JS.
	// The campaign pattern picker's BlockPreview needs core blocks registered
	// (registerCoreBlocks also sets the missing-block fallback handler so
	// non-core blocks in pattern content degrade gracefully instead of being
	// dropped by parse()).
	if (!getBlockType('core/paragraph')) {
		registerCoreBlocks();
	}

	initAccentColorPicker();
	mountCampaignPatternControl();

	const audienceSelect = getAudienceSelect();
	const segmentSelect = getSegmentSelect();

	if (!audienceSelect || !segmentSelect) {
		return;
	}

	const savedSegment =
		segmentSelect.dataset.savedSegment ??
		segmentSelect.getAttribute('data-saved-segment') ??
		'';

	audienceSelect.addEventListener('change', () => {
		void loadSegments(audienceSelect.value);
	});

	segmentSelect.addEventListener('change', () => {
		const audienceId = audienceSelect.value;
		if (!audienceId) {
			const el = getSubscriberCountElement();
			if (el) {
				el.textContent = '—';
			}
			return;
		}

		if (segmentSelect.value) {
			void (async () => {
				try {
					const segments = await apiFetch<Segment[]>({
						path: `/${config.restNamespace}/audiences/${encodeURIComponent(audienceId)}/segments`,
					});
					updateSubscriberCountFromSegmentSelection(
						segments,
						segmentSelect.value
					);
				} catch {
					void fetchAudienceSubscriberCount(audienceId);
				}
			})();
		} else {
			void fetchAudienceSubscriberCount(audienceId);
		}
	});

	if (audienceSelect.value) {
		void loadSegments(audienceSelect.value, savedSegment);
	} else {
		showNoAudienceState(segmentSelect);
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', init);
} else {
	init();
}
