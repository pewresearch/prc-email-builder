/**
 * Newsletter List term edit control for pinning an archive preview campaign.
 */

import { WPEntitySearch } from '@prc/components';
import { Button } from '@wordpress/components';
import { createRoot, useCallback, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

interface TermAdminConfig {
	previewCampaignMetaKey?: string;
	campaignPostType?: string;
	listTaxonomy?: string;
	termId?: number;
}

interface EntitySearchItem {
	entityId: number;
	entityName: string;
}

const config: TermAdminConfig =
	(window as { prcEmailBuilderTermAdmin?: TermAdminConfig })
		.prcEmailBuilderTermAdmin ?? {};

const META_INPUT_ID =
	config.previewCampaignMetaKey ?? 'prc_newsletter_list_preview_campaign_id';
const CAMPAIGN_POST_TYPE = config.campaignPostType ?? 'prc_email_campaign';
const LIST_TAXONOMY = config.listTaxonomy ?? 'prc_newsletter_list';
const LIST_TERM_ID = Number(config.termId ?? 0);
const PUBLISHED_STATUS = ['publish'];

function getMetaInput(): HTMLInputElement | null {
	return document.getElementById(META_INPUT_ID) as HTMLInputElement | null;
}

function PreviewCampaignControl() {
	const [campaignId, setCampaignId] = useState(
		() => getMetaInput()?.value ?? ''
	);
	const [campaignTitle, setCampaignTitle] = useState(
		() => getMetaInput()?.dataset.title ?? ''
	);

	const persistCampaign = useCallback((nextId: string, nextTitle: string) => {
		setCampaignId(nextId);
		setCampaignTitle(nextTitle);
		const input = getMetaInput();
		if (input) {
			input.value = nextId;
			input.dataset.title = nextTitle;
		}
	}, []);

	const handleSelect = useCallback(
		(item: EntitySearchItem) => {
			const nextId = String(item.entityId ?? '');
			if (!nextId || nextId === '0') {
				return;
			}
			persistCampaign(nextId, item.entityName ?? '');
		},
		[persistCampaign]
	);

	const selectedId = campaignId ? Number(campaignId) : undefined;
	const selectedLabel =
		campaignTitle ||
		(campaignId ? sprintfCampaignFallback(campaignId) : '');

	return (
		<div className="prc-newsletter-list-preview-campaign">
			{campaignId ? (
				<p className="prc-newsletter-list-preview-campaign__selected">
					<strong>
						{__('Selected newsletter:', 'prc-email-builder')}
					</strong>{' '}
					{selectedLabel}
				</p>
			) : (
				<p className="description">
					{__('Latest published newsletter', 'prc-email-builder')}
				</p>
			)}

			<WPEntitySearch
				placeholder={__(
					'Search published campaigns…',
					'prc-email-builder'
				)}
				entityType="postType"
				entitySubType={CAMPAIGN_POST_TYPE}
				entityStatus={PUBLISHED_STATUS}
				entityId={selectedId}
				taxonomy={LIST_TERM_ID > 0 ? LIST_TAXONOMY : ''}
				termId={LIST_TERM_ID}
				onSelect={handleSelect}
				clearOnSelect
				showExcerpt={false}
				showType={false}
				showUrl
				searchSize="large"
			/>

			{campaignId ? (
				<div className="prc-newsletter-list-preview-campaign__actions">
					<Button
						variant="tertiary"
						isDestructive
						__next40pxDefaultSize
						onClick={() => persistCampaign('', '')}
					>
						{__('Use latest published', 'prc-email-builder')}
					</Button>
				</div>
			) : null}
		</div>
	);
}

function sprintfCampaignFallback(campaignId: string): string {
	return sprintf(
		/* translators: %s: campaign post ID */
		__('Campaign #%s', 'prc-email-builder'),
		campaignId
	);
}

export function mountPreviewCampaignControl(): void {
	const container = document.getElementById(
		'prc-newsletter-list-preview-campaign-root'
	);
	if (!container) {
		return;
	}

	const root = createRoot(container);
	root.render(<PreviewCampaignControl />);
}
