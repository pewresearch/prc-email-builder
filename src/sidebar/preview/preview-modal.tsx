/**
 * Email preview modal.
 *
 * Layout zones (top → bottom):
 *  1. InboxBar        — compact horizontal FROM / SUBJECT / PREVIEW bar
 *  2. Toolbar         — VIEWPORT / SCHEME / VIEW toggle groups with all-caps labels
 *  3. Content         — size badge + browser chrome + iframe or HtmlView (scrollable)
 *  4. TestSendFooter  — pinned to modal bottom via flex layout (outside scroll region)
 */

import { __ } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import {
	Modal,
	Button,
	Notice,
	Spinner,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { copy, update } from '@wordpress/icons';

import { usePreview } from './use-preview';
import type { PreviewData } from './use-preview';
import { TestSendFooter } from './test-send-footer';

import './style.scss';

// ─── Constants ───────────────────────────────────────────────────────────────

const DESKTOP_WIDTH = 600;
const MOBILE_WIDTH = 375;
const GMAIL_CLIP_KB = 102;
const GMAIL_WARN_KB = 100;

type Viewport = 'desktop' | 'mobile';
type ColorMode = 'light' | 'dark';
type ActiveTab = 'preview' | 'html';

const DARK_MODE_PREVIEW_STYLE =
	'<style>body,table,td,div,p,h1,h2,h3,h4,h5,h6,li,blockquote{background-color:#1a1a1a!important;color:#f0f0f0!important;-webkit-text-fill-color:#f0f0f0!important;}a{color:#5B9BD5!important;-webkit-text-fill-color:#5B9BD5!important;}</style>';

function injectPreviewDarkStyle(html: string, darkStyle: string): string {
	if (!darkStyle) {
		return html;
	}
	if (html.includes('</head>')) {
		return html.replace('</head>', `${darkStyle}</head>`);
	}
	if (html.includes('<body')) {
		return html.replace('<body', `${darkStyle}<body`);
	}
	return `${darkStyle}${html}`;
}

// ─── InboxBar ────────────────────────────────────────────────────────────────

function InboxBar({ data }: { data: PreviewData | null }) {
	const defaults: { from_name: string; from_email: string } =
		(window as any).prcEmailBuilderConfig?.defaults ?? {};

	const fromName = data?.from_name || defaults.from_name || '';
	const fromEmail = data?.from_email || defaults.from_email || '';
	const fromDisplay = [fromName, fromEmail].filter(Boolean).join(' — ');
	const subject = data?.subject || '';
	const previewText = data?.preview_text || '';

	return (
		<div className="prc-email-preview__inbox-bar">
			<span className="prc-email-preview__inbox-segment">
				<span className="prc-email-preview__inbox-label">FROM</span>
				<span className="prc-email-preview__inbox-value">
					{fromDisplay || <em>—</em>}
				</span>
			</span>

			<span className="prc-email-preview__inbox-sep" aria-hidden="true">
				|
			</span>

			<span className="prc-email-preview__inbox-segment">
				<span className="prc-email-preview__inbox-label">SUBJECT</span>
				<span className="prc-email-preview__inbox-value">
					{subject || <em>—</em>}
				</span>
			</span>

			{previewText && (
				<>
					<span
						className="prc-email-preview__inbox-sep"
						aria-hidden="true"
					>
						|
					</span>
					<span className="prc-email-preview__inbox-segment">
						<span className="prc-email-preview__inbox-label">
							PREVIEW
						</span>
						<span className="prc-email-preview__inbox-value">
							{previewText}
						</span>
					</span>
				</>
			)}
		</div>
	);
}

// ─── SizeIndicator ───────────────────────────────────────────────────────────

function sizeBadgeVariant(kb: number): 'error' | 'warning' | 'ok' {
	if (kb >= GMAIL_CLIP_KB) {
		return 'error';
	}
	if (kb >= GMAIL_WARN_KB) {
		return 'warning';
	}
	return 'ok';
}

function sizeBadgeLabel(
	kb: number,
	variant: 'error' | 'warning' | 'ok'
): string {
	const size = `${kb.toFixed(1)} KB`;
	if (variant === 'error') {
		return `${size} — Gmail will clip this email`;
	}
	if (variant === 'warning') {
		return `${size} — approaching Gmail limit`;
	}
	return `${size} — within Gmail limits`;
}

export function SizeIndicator({ sizeBytes }: { sizeBytes: number }) {
	if (sizeBytes <= 0) {
		return null;
	}

	const kb = sizeBytes / 1024;
	const variant = sizeBadgeVariant(kb);
	const label = sizeBadgeLabel(kb, variant);

	return (
		<div
			className={`prc-email-preview__size-badge prc-email-preview__size-badge--${variant}`}
		>
			<span className="prc-email-preview__size-dot" aria-hidden="true">
				●
			</span>
			{label}
		</div>
	);
}

// ─── HtmlView ────────────────────────────────────────────────────────────────

export function HtmlView({ html }: { html: string }) {
	const [copied, setCopied] = useState(false);

	const handleCopy = useCallback(() => {
		navigator.clipboard.writeText(html).then(() => {
			setCopied(true);
			setTimeout(() => setCopied(false), 2000);
		});
	}, [html]);

	return (
		<div className="prc-email-preview__html-view">
			<div className="prc-email-preview__html-toolbar">
				<Button
					size="small"
					icon={copy}
					onClick={handleCopy}
					label={__('Copy HTML', 'prc-email-builder')}
				>
					{copied
						? __('Copied!', 'prc-email-builder')
						: __('Copy HTML', 'prc-email-builder')}
				</Button>
			</div>
			<pre className="prc-email-preview__html-pre">
				<code>{html}</code>
			</pre>
		</div>
	);
}

// ─── PreviewFrame (with browser chrome) ──────────────────────────────────────

function PreviewFrame({
	html,
	viewport,
	colorMode,
	subject,
	fromName,
	fromEmail,
}: {
	html: string;
	viewport: Viewport;
	colorMode: ColorMode;
	subject?: string;
	fromName?: string;
	fromEmail?: string;
}) {
	const width = viewport === 'desktop' ? DESKTOP_WIDTH : MOBILE_WIDTH;

	const darkStyle = colorMode === 'dark' ? DARK_MODE_PREVIEW_STYLE : '';
	const srcDoc = injectPreviewDarkStyle(html, darkStyle);

	const addressText = [subject, fromName, fromEmail]
		.filter(Boolean)
		.join(' — ');

	return (
		<div className="prc-email-preview__frame-wrapper">
			<div
				className="prc-email-preview__browser-chrome"
				style={{ width: `${width}px`, maxWidth: '100%' }}
			>
				<div className="prc-email-preview__browser-bar">
					<div
						className="prc-email-preview__browser-dots"
						aria-hidden="true"
					>
						<span />
						<span />
						<span />
					</div>
					<div className="prc-email-preview__browser-address">
						{addressText}
					</div>
				</div>
				<iframe
					key={colorMode}
					className="prc-email-preview__iframe"
					srcDoc={srcDoc}
					title={__('Email preview', 'prc-email-builder')}
					style={{ width: `${width}px`, maxWidth: '100%' }}
					sandbox="allow-same-origin"
				/>
			</div>
		</div>
	);
}

// ─── Main modal ──────────────────────────────────────────────────────────────

interface PreviewModalProps {
	postId: number;
	onClose: () => void;
	testEmails: string[];
	onTestEmailsChange: (emails: string[]) => void;
}

export function PreviewModal({
	postId,
	onClose,
	testEmails,
	onTestEmailsChange,
}: PreviewModalProps) {
	const [viewport, setViewport] = useState<Viewport>('desktop');
	const [colorMode, setColorMode] = useState<ColorMode>('light');
	const [activeTab, setActiveTab] = useState<ActiveTab>('preview');

	const { fetchStatus, data, errorMessage, refresh } = usePreview(
		postId,
		true
	);

	const isLoading = fetchStatus === 'loading' || fetchStatus === 'pending';
	const html = data?.html ?? '';

	return (
		<Modal
			title={__('Email preview', 'prc-email-builder')}
			onRequestClose={onClose}
			size="fill"
			className="prc-email-preview__modal"
			headerActions={
				<Button
					size="small"
					icon={update}
					onClick={() => {
						void refresh();
					}}
					disabled={isLoading}
					label={__('Regenerate email preview', 'prc-email-builder')}
				/>
			}
		>
			<div
				className="prc-email-preview__layout"
				data-prc-tour="email-preview"
			>
				{/* Zone 1: Inbox metadata bar */}
				<InboxBar data={data} />

				{/* Zone 2: Toolbar */}
				<div className="prc-email-preview__toolbar">
					<div className="prc-email-preview__toolbar-group">
						<span className="prc-email-preview__toolbar-label">
							VIEWPORT
						</span>
						<ToggleGroupControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={__('Viewport', 'prc-email-builder')}
							value={viewport}
							onChange={(v) => setViewport(v as Viewport)}
							isBlock={false}
						>
							<ToggleGroupControlOption
								value="desktop"
								label={__('Desktop', 'prc-email-builder')}
							/>
							<ToggleGroupControlOption
								value="mobile"
								label={__('Mobile', 'prc-email-builder')}
							/>
						</ToggleGroupControl>
					</div>

					<div className="prc-email-preview__toolbar-group">
						<span className="prc-email-preview__toolbar-label">
							SCHEME
						</span>
						<ToggleGroupControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={__('Color scheme', 'prc-email-builder')}
							value={colorMode}
							onChange={(v) => setColorMode(v as ColorMode)}
							isBlock={false}
						>
							<ToggleGroupControlOption
								value="light"
								label={__('Light', 'prc-email-builder')}
							/>
							<ToggleGroupControlOption
								value="dark"
								label={__('Dark', 'prc-email-builder')}
							/>
						</ToggleGroupControl>
					</div>

					<div className="prc-email-preview__toolbar-group">
						<span className="prc-email-preview__toolbar-label">
							VIEW
						</span>
						<ToggleGroupControl
							__nextHasNoMarginBottom
							hideLabelFromVision
							label={__('View', 'prc-email-builder')}
							value={activeTab}
							onChange={(v) => setActiveTab(v as ActiveTab)}
							isBlock={false}
						>
							<ToggleGroupControlOption
								value="preview"
								label={__('Preview', 'prc-email-builder')}
							/>
							<ToggleGroupControlOption
								value="html"
								label="HTML"
							/>
						</ToggleGroupControl>
					</div>
				</div>

				{/* Zone 3: Scrollable content area */}
				<div className="prc-email-preview__content">
					{isLoading && (
						<HStack spacing={2} alignment="left">
							<Spinner />
							<Text>
								{fetchStatus === 'loading'
									? __(
											'Fetching preview…',
											'prc-email-builder'
										)
									: __(
											'Generating email HTML — this may take a minute…',
											'prc-email-builder'
										)}
							</Text>
						</HStack>
					)}

					{fetchStatus === 'error' && (
						<Notice status="error" isDismissible={false}>
							{errorMessage ??
								__(
									'An error occurred while generating the preview.',
									'prc-email-builder'
								)}
						</Notice>
					)}

					{fetchStatus === 'complete' && html && (
						<>
							<SizeIndicator sizeBytes={data?.size_bytes ?? 0} />

							{activeTab === 'preview' ? (
								<PreviewFrame
									html={html}
									viewport={viewport}
									colorMode={colorMode}
									subject={data?.subject}
									fromName={data?.from_name}
									fromEmail={data?.from_email}
								/>
							) : (
								<HtmlView html={html} />
							)}
						</>
					)}
				</div>

				{/* Zone 4: Test-send footer (pinned to modal bottom) */}
				<TestSendFooter
					postId={postId}
					testEmails={testEmails}
					onTestEmailsChange={onTestEmailsChange}
				/>
			</div>
		</Modal>
	);
}
