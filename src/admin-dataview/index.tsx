/**
 * WordPress Dependencies
 */
import { Button, createSlotFill, Flex } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { plus } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import { composeEmailActions } from './actions';
import { AudienceBuilderModal } from './audience-builder-modal';
import { mapAudienceQuery } from './audience-catalog';
import {
	AUDIENCE_DEFAULT_VISIBLE_FIELDS,
	getAudienceActions,
	getAudienceFields,
} from './audience-list';
import CampaignStatsModal from '../library/components/campaign-stats-modal';
import CreateCampaignDropdown from '../library/components/create-campaign-dropdown';
import GenerateLinksNewsletterModal from '../library/components/generate-links-newsletter-modal';
import { getDefaultVisibleFields, getFieldsForScope } from '../library/fields';
import {
	getEmailConfig,
	type EmailLibraryRow,
	type EmailPageScope,
} from '../library/types';
import '../library/style.scss';

declare const prcEmailBuilderLibraryAI:
	| {
			enabled: boolean;
			linksNewsletterAbilityName: string;
	  }
	| undefined;

const { Fill: PageExtrasFill } = createSlotFill(
	'prcWpAdminDataview.PageExtras'
);
const PAGE_EXTRA_EVENT = 'prcWpAdminDataview.pageExtra';

function getScope(): EmailPageScope | null {
	return (
		getEmailConfig()?.postTypeScope ??
		window.prcWpAdminDataview?.config?.postTypeScope ??
		null
	);
}

function emitPageExtra(type: string, payload?: EmailLibraryRow) {
	window.dispatchEvent(
		new CustomEvent(PAGE_EXTRA_EVENT, {
			detail: { type, payload },
		})
	);
}

function EmailPageExtras() {
	const [campaign, setCampaign] = useState<EmailLibraryRow | null>(null);
	const [isGenerateOpen, setIsGenerateOpen] = useState(false);

	useEffect(() => {
		const handlePageExtra = (event: Event) => {
			if (!(event instanceof CustomEvent)) {
				return;
			}
			if (event.detail?.type === 'campaign-stats') {
				setCampaign(event.detail.payload);
			}
			if (event.detail?.type === 'generate-links-newsletter') {
				setIsGenerateOpen(true);
			}
		};
		window.addEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
		return () =>
			window.removeEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
	}, []);

	return (
		<>
			<CampaignStatsModal
				campaign={campaign}
				onClose={() => setCampaign(null)}
			/>
			<GenerateLinksNewsletterModal
				isOpen={isGenerateOpen}
				onClose={() => setIsGenerateOpen(false)}
				onDraftCreated={() => window.location.reload()}
			/>
		</>
	);
}

function AudiencePageExtras() {
	const [isBuilderOpen, setIsBuilderOpen] = useState(false);

	useEffect(() => {
		const handlePageExtra = (event: Event) => {
			if (
				event instanceof CustomEvent &&
				event.detail?.type === 'add-audience'
			) {
				setIsBuilderOpen(true);
			}
		};
		window.addEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
		return () =>
			window.removeEventListener(PAGE_EXTRA_EVENT, handlePageExtra);
	}, []);

	return (
		<AudienceBuilderModal
			isOpen={isBuilderOpen}
			onClose={() => setIsBuilderOpen(false)}
		/>
	);
}

function EmailHeaderActions({ scope }: { readonly scope: EmailPageScope }) {
	if (scope === 'audience') {
		return (
			<Button
				__next40pxDefaultSize
				variant="primary"
				icon={plus}
				onClick={() => emitPageExtra('add-audience')}
			>
				{__('Add new', 'prc-email-builder')}
			</Button>
		);
	}

	const config = getEmailConfig();
	const isCampaign = scope === 'campaign';
	const isAIEnabled =
		isCampaign &&
		typeof prcEmailBuilderLibraryAI !== 'undefined' &&
		prcEmailBuilderLibraryAI.enabled;
	const transactionalNewUrl =
		config?.transactionalNewUrl ??
		`post-new.php?post_type=${config?.transactionalPostType ?? 'prc_email_txn'}`;

	return (
		<Flex gap={2} align="center" justify="flex-end">
			{isCampaign ? (
				<CreateCampaignDropdown />
			) : (
				<Button
					__next40pxDefaultSize
					variant="primary"
					href={transactionalNewUrl}
				>
					{__('Create new', 'prc-email-builder')}
				</Button>
			)}
			{isAIEnabled ? (
				<Button
					__next40pxDefaultSize
					variant="secondary"
					onClick={() => emitPageExtra('generate-links-newsletter')}
				>
					{__('Generate Links Newsletter', 'prc-email-builder')}
				</Button>
			) : null}
		</Flex>
	);
}

const PAGE_DESCRIPTIONS: Record<EmailPageScope, string> = {
	campaign: __('Browse and manage email campaigns.', 'prc-email-builder'),
	txn: __('Browse and manage transactional emails.', 'prc-email-builder'),
	audience: __(
		'Mailchimp newsletter lists for campaigns and recipient lists for bulk transactional emails, with send and engagement totals for each.',
		'prc-email-builder'
	),
};

function EmailFills({ scope }: { readonly scope: EmailPageScope }) {
	return (
		<>
			<span>{PAGE_DESCRIPTIONS[scope]}</span>
			<PageExtrasFill>
				{scope === 'audience' ? (
					<AudiencePageExtras />
				) : (
					<EmailPageExtras />
				)}
			</PageExtrasFill>
		</>
	);
}

addFilter('prcWpAdminDataview.fields', 'prc-email-builder/fields', (fields) => {
	const scope = getScope();
	if (!scope) {
		return fields;
	}
	if (scope === 'audience') {
		return [...fields, ...getAudienceFields()];
	}
	return [
		...fields,
		...getFieldsForScope(scope, {
			onOpenStats: (item) => emitPageExtra('campaign-stats', item),
		}),
	];
});

addFilter(
	'prcWpAdminDataview.actions',
	'prc-email-builder/actions',
	(actions, { onRefresh }) => {
		const scope = getScope();
		if (scope === 'audience') {
			return [...actions, ...getAudienceActions(onRefresh)];
		}
		return composeEmailActions(actions, scope);
	}
);

addFilter(
	'prcWpAdminDataview.defaultVisibleFields',
	'prc-email-builder/default-fields',
	(fields) => {
		const scope = getScope();
		if (!scope) {
			return fields;
		}
		return scope === 'audience'
			? AUDIENCE_DEFAULT_VISIBLE_FIELDS
			: getDefaultVisibleFields(scope);
	}
);

addFilter(
	'prcWpAdminDataview.headerActions',
	'prc-email-builder/header-actions',
	(actions) => {
		const scope = getScope();
		return scope ? <EmailHeaderActions scope={scope} /> : actions;
	}
);

addFilter(
	'prcWpAdminDataview.pageDescription',
	'prc-email-builder/page-description',
	(description) => {
		const scope = getScope();
		return scope ? <EmailFills scope={scope} /> : description;
	}
);

addFilter(
	'prcWpAdminDataview.restQuery',
	'prc-email-builder/rest-query',
	(args, { view }) => {
		const scope = getScope();
		if (!scope) {
			return args;
		}
		if (scope === 'audience') {
			return mapAudienceQuery(args, view.sort?.field);
		}

		const mappedArgs = {
			...args,
			post_type: scope,
		};
		if (mappedArgs.newsletterLists) {
			mappedArgs.newsletter_list = mappedArgs.newsletterLists;
			delete mappedArgs.newsletterLists;
		}

		if (mappedArgs.sendStatus) {
			const mailchimpStatuses: string[] = [];
			const mandrillStatuses: string[] = [];
			String(mappedArgs.sendStatus)
				.split(',')
				.forEach((value) => {
					const [prefix, status] = value.split(':', 2);
					if (prefix === 'campaign' && status) {
						mailchimpStatuses.push(status);
					}
					if (prefix === 'txn' && status) {
						mandrillStatuses.push(status);
					}
				});
			if (mailchimpStatuses.length) {
				mappedArgs.mailchimp_status = mailchimpStatuses.join(',');
			}
			if (mandrillStatuses.length) {
				mappedArgs.mandrill_status = mandrillStatuses.join(',');
			}
			delete mappedArgs.sendStatus;
		}

		const orderbyMap: Record<string, string> = {
			openRate: 'open_rate',
			clickRate: 'click_rate',
		};
		const sortField = view.sort?.field;
		if (sortField && orderbyMap[sortField]) {
			mappedArgs.orderby = orderbyMap[sortField];
		}

		return mappedArgs;
	}
);
