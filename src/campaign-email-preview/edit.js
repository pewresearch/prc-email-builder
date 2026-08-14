/**
 * WordPress Dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal Dependencies
 */
import { REST_NAMESPACE } from './constants';

export default function Edit({
	attributes,
	setAttributes,
	context,
	isSelected,
}) {
	const {
		frameWidth = 375,
		frameHeight = 560,
		showFade = true,
		showLink = true,
		linkText = __('Read the latest issue', 'prc-email-builder'),
		linkUrl = '',
	} = attributes;

	const postId = context?.postId ? Number(context.postId) : 0;

	const { permalink, title } = useSelect(
		(select) => {
			if (!postId) {
				return { permalink: '', title: '' };
			}
			const record = select(coreStore).getEntityRecord(
				'postType',
				'prc_email_campaign',
				postId
			);
			return {
				permalink: record?.link || '',
				title: record?.title?.rendered || record?.title?.raw || '',
			};
		},
		[postId]
	);

	const blockProps = useBlockProps({
		className: [
			'prc-email-builder-campaign-email-preview',
			showFade ? 'has-fade' : '',
		]
			.filter(Boolean)
			.join(' '),
		style: {
			'--preview-width': `${frameWidth}`,
			'--preview-height': `${frameHeight}`,
		},
	});

	const previewUrl = postId
		? `${window.wpApiSettings?.root || '/wp-json/'}${REST_NAMESPACE}/campaign-preview/${postId}`
		: '';
	const href = linkUrl.trim() || permalink;

	const iframeTitle = sprintf(
		/* translators: %s: campaign title */
		__('Email preview: %s', 'prc-email-builder'),
		title || __('Newsletter', 'prc-email-builder')
	);

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={__('Preview frame', 'prc-email-builder')}
					initialOpen={true}
				>
					<RangeControl
						label={__('Width (px)', 'prc-email-builder')}
						value={frameWidth}
						onChange={(value) =>
							setAttributes({ frameWidth: value })
						}
						min={240}
						max={480}
						step={1}
					/>
					<RangeControl
						label={__('Height (px)', 'prc-email-builder')}
						value={frameHeight}
						onChange={(value) =>
							setAttributes({ frameHeight: value })
						}
						min={320}
						max={900}
						step={1}
					/>
					<ToggleControl
						label={__('Show fade overlay', 'prc-email-builder')}
						checked={!!showFade}
						onChange={(value) => setAttributes({ showFade: value })}
					/>
					<ToggleControl
						label={__(
							'Show latest-issue link',
							'prc-email-builder'
						)}
						checked={!!showLink}
						onChange={(value) => setAttributes({ showLink: value })}
					/>
					{showLink && (
						<>
							<TextControl
								label={__('Link text', 'prc-email-builder')}
								value={linkText}
								onChange={(value) =>
									setAttributes({ linkText: value })
								}
							/>
							<TextControl
								label={__(
									'Link destination',
									'prc-email-builder'
								)}
								help={__(
									'Leave empty to use the campaign permalink.',
									'prc-email-builder'
								)}
								value={linkUrl}
								onChange={(value) =>
									setAttributes({ linkUrl: value })
								}
							/>
						</>
					)}
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>
				{!postId ? (
					<Placeholder
						label={__(
							'Campaign Email Preview',
							'prc-email-builder'
						)}
						instructions={__(
							'Place this block inside a Latest Newsletter Preview query (or any Query Loop that provides a campaign post) to show a live email preview.',
							'prc-email-builder'
						)}
					/>
				) : (
					<>
						<div className="prc-email-builder-campaign-email-preview__frame">
							<iframe
								className="prc-email-builder-campaign-email-preview__iframe"
								src={previewUrl}
								title={iframeTitle}
								loading="lazy"
								sandbox="allow-popups allow-popups-to-escape-sandbox"
								style={{
									pointerEvents: isSelected ? 'auto' : 'none',
								}}
							/>
							{showFade && (
								<div
									className="prc-email-builder-campaign-email-preview__fade"
									aria-hidden="true"
								/>
							)}
						</div>
						{showLink && href && (
							<p className="prc-email-builder-campaign-email-preview__link">
								<a
									href={href}
									target="_blank"
									rel="noopener noreferrer"
								>
									{linkText}
								</a>
							</p>
						)}
					</>
				)}
			</div>
		</>
	);
}
