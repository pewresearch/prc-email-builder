/**
 * Create a new campaign draft from a Newsletter List's associated pattern.
 */

import { __ } from '@wordpress/i18n';
import { useCallback, useMemo, useState } from '@wordpress/element';
import {
	DropdownMenu,
	MenuGroup,
	MenuItem,
	Notice,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { useEmailPatterns } from '../../sidebar/pattern-selector/use-email-patterns';
import { getEmailConfig } from '../types';

interface NewsletterListOption {
	termId: number;
	slug: string;
	label: string;
	campaignPattern: string;
}

type ListAvailability = 'ready' | 'missing' | 'unavailable';

interface ResolvedList {
	term: NewsletterListOption;
	availability: ListAvailability;
	content: string;
	info: string;
}

export default function CreateCampaignDropdown() {
	const [isCreating, setIsCreating] = useState(false);
	const [error, setError] = useState<string | null>(null);
	const config = getEmailConfig();

	const categorySlug =
		config?.campaignPatternCategorySlug ?? 'email-campaign';
	const postType = config?.campaignPostType ?? 'prc_email_campaign';
	const taxonomy = config?.newsletterListTaxonomy ?? 'prc_newsletter_list';

	const { patterns, isLoading } = useEmailPatterns(categorySlug, true);

	const resolvedLists = useMemo<ResolvedList[]>(() => {
		const lists: NewsletterListOption[] = config?.newsletterLists ?? [];
		const patternsByName = new Map(
			patterns.map((pattern) => [pattern.name, pattern])
		);

		return lists.map((term) => {
			if (!term.campaignPattern) {
				return {
					term,
					availability: 'missing' as const,
					content: '',
					info: __('No pattern assigned', 'prc-email-builder'),
				};
			}

			const pattern = patternsByName.get(term.campaignPattern);
			if (!pattern?.content) {
				return {
					term,
					availability: 'unavailable' as const,
					content: '',
					info: __('Pattern unavailable', 'prc-email-builder'),
				};
			}

			return {
				term,
				availability: 'ready' as const,
				content: pattern.content,
				info: decodeEntities(pattern.title),
			};
		});
	}, [config?.newsletterLists, patterns]);

	const createDraftAndNavigate = useCallback(
		async (data: Record<string, unknown>, onClose: () => void) => {
			if (isCreating) {
				return;
			}

			setIsCreating(true);
			setError(null);
			onClose();

			try {
				const post = await apiFetch<{ id: number }>({
					path: `/wp/v2/${postType}`,
					method: 'POST',
					data: {
						status: 'draft',
						...data,
					},
				});

				const editUrl = `${config?.postEditUrl || 'post.php'}?post=${post.id}&action=edit`;
				window.location.href = editUrl;
			} catch (err) {
				const message =
					err instanceof Error
						? err.message
						: __(
								'Failed to create the email draft.',
								'prc-email-builder'
							);
				setError(message);
				setIsCreating(false);
			}
		},
		[config?.postEditUrl, isCreating, postType]
	);

	const handleCreate = useCallback(
		async (list: ResolvedList, onClose: () => void) => {
			if (list.availability !== 'ready') {
				return;
			}

			await createDraftAndNavigate(
				{
					content: list.content,
					[taxonomy]: [list.term.termId],
				},
				onClose
			);
		},
		[createDraftAndNavigate, taxonomy]
	);

	const handleCreateBlank = useCallback(() => {
		const newUrl =
			config?.campaignNewUrl ?? `post-new.php?post_type=${postType}`;
		window.location.href = newUrl;
	}, [config?.campaignNewUrl, postType]);

	if (isCreating) {
		return (
			<div
				className="prc-email-library-create-campaign is-busy"
				data-prc-tour="email-create-campaign"
			>
				<Spinner />
				<span>{__('Creating draft…', 'prc-email-builder')}</span>
			</div>
		);
	}

	const createLabel = isLoading
		? __('Loading…', 'prc-email-builder')
		: __('Create new', 'prc-email-builder');

	return (
		<div
			className="prc-email-library-create-campaign"
			data-prc-tour="email-create-campaign"
		>
			{error ? (
				<Notice
					status="error"
					isDismissible
					onRemove={() => setError(null)}
				>
					{error}
				</Notice>
			) : null}
			<DropdownMenu
				icon={null}
				label={createLabel}
				text={createLabel}
				popoverProps={{
					placement: 'bottom-end',
				}}
				toggleProps={{
					variant: 'primary',
					disabled: isLoading,
					isBusy: isLoading,
					showTooltip: false,
				}}
			>
				{({ onClose }) => (
					<>
						<MenuGroup
							label={__('Newsletter lists', 'prc-email-builder')}
						>
							{isLoading ? (
								<MenuItem disabled>
									{__(
										'Loading patterns…',
										'prc-email-builder'
									)}
								</MenuItem>
							) : null}
							{!isLoading && resolvedLists.length === 0 ? (
								<MenuItem disabled>
									{__(
										'No newsletter lists found.',
										'prc-email-builder'
									)}
								</MenuItem>
							) : null}
							{!isLoading
								? resolvedLists.map((list) => {
										const disabled =
											list.availability !== 'ready';
										return (
											<MenuItem
												key={list.term.termId}
												disabled={disabled}
												info={list.info}
												onClick={() => {
													void handleCreate(
														list,
														onClose
													);
												}}
											>
												{list.term.label}
											</MenuItem>
										);
									})
								: null}
						</MenuGroup>
						<MenuGroup>
							<MenuItem
								info={__(
									'Choose a pattern in the editor',
									'prc-email-builder'
								)}
								onClick={() => {
									handleCreateBlank();
								}}
							>
								{__('Create blank', 'prc-email-builder')}
							</MenuItem>
						</MenuGroup>
					</>
				)}
			</DropdownMenu>
		</div>
	);
}
