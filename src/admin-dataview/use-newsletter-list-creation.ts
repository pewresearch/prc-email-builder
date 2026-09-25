import apiFetch from '@wordpress/api-fetch';
import { useCallback, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';

import { getAudienceErrorMessage } from './audience-builder-views';
import {
	canCreateNewsletterList,
	INITIAL_NEWSLETTER_LIST,
	toNewsletterListPayload,
	type NewsletterListInput,
} from './newsletter-list-input';
import { createCampaignForList } from '../library/create-campaign-draft';
import { getEmailConfig } from '../library/types';

export interface CreatedNewsletterList {
	readonly termId: number;
	readonly name: string;
	readonly campaignPattern: string;
}

/**
 * State and actions for adding a newsletter list, then starting a campaign from it.
 *
 * @param onCreated Called after the term is saved (for list refresh).
 */
export function useNewsletterListCreation(onCreated: () => void) {
	const [input, setInput] = useState<NewsletterListInput>(
		INITIAL_NEWSLETTER_LIST
	);
	const [error, setError] = useState<string | null>(null);
	const [isSaving, setIsSaving] = useState(false);
	const [created, setCreated] = useState<CreatedNewsletterList | null>(null);

	const reset = useCallback(() => {
		setInput(INITIAL_NEWSLETTER_LIST);
		setError(null);
		setIsSaving(false);
		setCreated(null);
	}, []);

	const create = useCallback(async (): Promise<boolean> => {
		if (!canCreateNewsletterList(input) || isSaving) {
			return false;
		}
		setIsSaving(true);
		setError(null);
		try {
			const taxonomy =
				getEmailConfig()?.newsletterListTaxonomy ??
				'prc_newsletter_list';
			const term = await apiFetch<{ id: number; name: string }>({
				path: `/wp/v2/${taxonomy}`,
				method: 'POST',
				data: toNewsletterListPayload(input),
			});
			setCreated({
				termId: term.id,
				name: decodeEntities(term.name),
				campaignPattern: input.campaignPattern,
			});
			onCreated();
			return true;
		} catch (reason) {
			setError(getAudienceErrorMessage(reason));
			return false;
		} finally {
			setIsSaving(false);
		}
	}, [input, isSaving, onCreated]);

	const createCampaign = useCallback(async () => {
		if (!created || isSaving) {
			return;
		}
		setIsSaving(true);
		setError(null);
		try {
			window.location.assign(
				await createCampaignForList(
					created.termId,
					created.campaignPattern
				)
			);
		} catch (reason) {
			setError(getAudienceErrorMessage(reason));
			setIsSaving(false);
		}
	}, [created, isSaving]);

	return {
		input,
		setInput,
		error,
		clearError: () => setError(null),
		isSaving,
		created,
		canCreate:
			canCreateNewsletterList(input) &&
			getEmailConfig()?.mailchimpConnected !== false,
		reset,
		create,
		createCampaign,
	};
}
