/**
 * Pattern selector modal for blank email campaigns and transactional emails.
 */

import { __ } from '@wordpress/i18n';
import { layout, envelope } from '@wordpress/icons';
import {
	useCallback,
	useMemo,
	useState,
	createInterpolateElement,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { parse } from '@wordpress/blocks';
import {
	BlockEditorProvider,
	BlockPreview,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import {
	Button,
	ExternalLink,
	Icon,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { store as editorStore } from '@wordpress/editor';

import {
	dismissPatternSelector,
	isBlankEmailContent,
	isPatternSelectorDismissed,
} from './is-blank-campaign-content';
import { useEmailPatterns, type EmailPatternItem } from './use-email-patterns';
import {
	config,
	isCampaignPostType,
	isTransactionalPostType,
	useNewsletterMeta,
} from '../use-newsletter-data';

import './style.scss';

const EMPTY_BLOCKS: ReturnType<typeof parse> = [];
const PREVIEW_VIEWPORT_WIDTH = 600;
const DEFAULT_PREVIEW_SIZE = 290;

const PATTERN_VIEW = {
	type: 'grid' as const,
	page: 1,
	perPage: 20,
	search: '',
	filters: [],
	titleField: 'title',
	mediaField: 'preview',
	fields: [],
	layout: {
		mediaField: 'preview',
		primaryField: 'title',
		previewSize: DEFAULT_PREVIEW_SIZE,
	},
};

type EmailEditorKind = 'campaign' | 'transactional';

interface PatternPickerItem extends EmailPatternItem {
	blocks: ReturnType<typeof parse> | null;
}

function getEmailEditorKind(
	postType: string | undefined
): EmailEditorKind | null {
	if (isCampaignPostType(postType)) {
		return 'campaign';
	}
	if (isTransactionalPostType(postType)) {
		return 'transactional';
	}
	return null;
}

function getPatternCategorySlug(kind: EmailEditorKind): string {
	if (kind === 'campaign') {
		return config.campaignPatternCategorySlug ?? 'email-campaign';
	}
	return config.transactionalPatternCategorySlug ?? 'email-transactional';
}

function getSiteEditorPatternCategoryUrl(categorySlug: string): string {
	const siteUrl = (window as { prcPlatform?: { siteUrl?: string } })
		.prcPlatform?.siteUrl;
	if (!siteUrl || !categorySlug) {
		return '';
	}

	const base = siteUrl.replace(/\/$/, '');
	const params = new URLSearchParams({
		path: '/pattern',
		postType: 'wp_block',
		categoryId: categorySlug,
	});
	return `${base}/wp-admin/site-editor.php?${params.toString()}`;
}

function PatternPreviewField({ item }: { item: PatternPickerItem }) {
	const isBlank = item.name === '__blank__';

	const blocks = useMemo(() => {
		if (isBlank) {
			return null;
		}

		return (
			item.blocks ??
			parse(item.content, {
				__unstableSkipMigrationLogs: true,
			})
		);
	}, [isBlank, item.blocks, item.content]);

	if (isBlank) {
		return (
			<div className="prc-email-pattern-selector__preview prc-email-pattern-selector__preview--blank">
				<Icon icon={layout} size={48} />
				<span>{__('Start blank', 'prc-email-builder')}</span>
			</div>
		);
	}

	if (!blocks?.length) {
		return (
			<div className="prc-email-pattern-selector__preview prc-email-pattern-selector__preview--fallback">
				<Icon icon={envelope} size={48} />
			</div>
		);
	}

	return (
		<div className="prc-email-pattern-selector__preview">
			<BlockPreview
				blocks={blocks}
				viewportWidth={PREVIEW_VIEWPORT_WIDTH}
			/>
		</div>
	);
}

function PatternPicker({
	patterns,
	isLoading,
	error,
	editorKind,
	patternCategorySlug,
	onSelect,
	onDismiss,
}: {
	patterns: EmailPatternItem[];
	isLoading: boolean;
	error: string | null;
	editorKind: EmailEditorKind;
	patternCategorySlug: string;
	onSelect: (item: PatternPickerItem) => void;
	onDismiss: () => void;
}) {
	const [view, setView] = useState(PATTERN_VIEW);

	const isTransactional = editorKind === 'transactional';

	const blankItem = useMemo<PatternPickerItem>(
		() => ({
			name: '__blank__',
			title: __('Start blank', 'prc-email-builder'),
			description: isTransactional
				? __(
						'Begin with an empty transactional email and add blocks manually.',
						'prc-email-builder'
					)
				: __(
						'Begin with an empty campaign and add blocks manually.',
						'prc-email-builder'
					),
			content: '',
			blocks: null,
		}),
		[isTransactional]
	);

	const allItems = useMemo(
		() => [
			blankItem,
			...patterns.map((pattern) => ({
				...pattern,
				blocks: null,
			})),
		],
		[blankItem, patterns]
	);

	const fields = useMemo(
		() => [
			{
				id: 'preview',
				label: __('Preview', 'prc-email-builder'),
				render: ({ item }: { item: PatternPickerItem }) => (
					<PatternPreviewField item={item} />
				),
				enableSorting: false,
				enableHiding: false,
			},
			{
				id: 'title',
				type: 'text' as const,
				label: __('Title', 'prc-email-builder'),
				getValue: ({ item }: { item: PatternPickerItem }) => item.title,
				enableSorting: false,
				enableHiding: false,
				enableGlobalSearch: true,
			},
		],
		[]
	);

	const siteEditorUrl = getSiteEditorPatternCategoryUrl(patternCategorySlug);
	const siteEditorLink = siteEditorUrl ? (
		<ExternalLink href={siteEditorUrl} />
	) : (
		<span />
	);

	const intro = isTransactional
		? createInterpolateElement(
				__(
					'Choose a starter layout for this transactional email. Patterns can be managed in the <a>Site Editor</a> under the Transactional Email category.',
					'prc-email-builder'
				),
				{ a: siteEditorLink }
			)
		: createInterpolateElement(
				__(
					'Choose a starter layout for this campaign. Patterns can be managed in the <a>Site Editor</a> under the Email Campaign category.',
					'prc-email-builder'
				),
				{ a: siteEditorLink }
			);

	if (isLoading) {
		return (
			<div className="prc-email-pattern-selector__loading">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="prc-email-pattern-selector__picker">
			<p className="prc-email-pattern-selector__intro">{intro}</p>

			{error && (
				<Notice status="warning" isDismissible={false}>
					{error}
				</Notice>
			)}

			<BlockEditorProvider value={EMPTY_BLOCKS} settings={{}}>
				<DataViews
					data={allItems}
					fields={fields}
					view={view}
					onChangeView={setView}
					defaultLayouts={{
						grid: {
							layout: {
								mediaField: 'preview',
								primaryField: 'title',
								previewSize: DEFAULT_PREVIEW_SIZE,
							},
						},
					}}
					paginationInfo={{
						totalItems: allItems.length,
						totalPages: 1,
					}}
					getItemId={(item) => item.name}
					isItemClickable={() => true}
					onClickItem={(item) => onSelect(item)}
					search={false}
				/>
			</BlockEditorProvider>

			<div className="prc-email-pattern-selector__actions">
				<Button variant="tertiary" onClick={onDismiss}>
					{__('Skip for now', 'prc-email-builder')}
				</Button>
			</div>
		</div>
	);
}

export function EmailPatternSelectorModal() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);
	const postId = useSelect(
		(select) => select(editorStore).getCurrentPostId(),
		[]
	);
	const blocks = useSelect(
		(select) => select(blockEditorStore).getBlocks(),
		[]
	);
	const isPostEmpty = useSelect(
		(select) => select(editorStore).isEditedPostEmpty(),
		[]
	);

	const editorKind = getEmailEditorKind(postType);
	const patternCategorySlug = editorKind
		? getPatternCategorySlug(editorKind)
		: '';

	const { campaignId } = useNewsletterMeta();
	const { resetBlocks } = useDispatch(blockEditorStore);

	const [isOpen, setIsOpen] = useState(true);
	const [hasAppliedSelection, setHasAppliedSelection] = useState(false);

	const isBlankAndDismissible =
		!!postId &&
		isPostEmpty &&
		isBlankEmailContent(blocks) &&
		!isPatternSelectorDismissed(postId);

	const shouldLoadPatterns =
		isOpen &&
		!!editorKind &&
		isBlankAndDismissible &&
		(editorKind === 'transactional' ||
			(editorKind === 'campaign' && !campaignId));

	const { patterns, isLoading, error } = useEmailPatterns(
		patternCategorySlug,
		shouldLoadPatterns
	);

	const shouldShowModal =
		shouldLoadPatterns && isOpen && !hasAppliedSelection;

	const closeModal = useCallback(() => {
		if (postId) {
			dismissPatternSelector(postId);
		}
		setIsOpen(false);
	}, [postId]);

	const handleSelect = useCallback(
		(item: PatternPickerItem) => {
			if (item.name !== '__blank__' && item.content) {
				resetBlocks(
					parse(item.content, {
						__unstableSkipMigrationLogs: true,
					})
				);
			}

			setHasAppliedSelection(true);
			closeModal();
		},
		[closeModal, resetBlocks]
	);

	if (!shouldShowModal || !editorKind) {
		return null;
	}

	return (
		<Modal
			className="prc-email-pattern-selector__modal"
			title={__('Choose an email pattern', 'prc-email-builder')}
			onRequestClose={closeModal}
			shouldCloseOnClickOutside={false}
			shouldCloseOnEsc={false}
		>
			<PatternPicker
				patterns={patterns}
				isLoading={isLoading}
				error={error}
				editorKind={editorKind}
				patternCategorySlug={patternCategorySlug}
				onSelect={handleSelect}
				onDismiss={closeModal}
			/>
		</Modal>
	);
}

export default function EmailPatternSelector() {
	const postType = useSelect(
		(select) => select(editorStore).getCurrentPostType(),
		[]
	);

	if (!getEmailEditorKind(postType)) {
		return null;
	}

	return <EmailPatternSelectorModal />;
}
