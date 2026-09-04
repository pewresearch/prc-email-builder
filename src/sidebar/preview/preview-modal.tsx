/**
 * Email preview modal.
 *
 * Layout zones (top → bottom):
 *  1. InboxBar        — compact horizontal FROM / SUBJECT / PREVIEW bar
 *  2. Toolbar         — VIEWPORT / SCHEME / VIEW toggle groups with all-caps labels
 *  3. Content         — size badge + browser chrome + iframe or HtmlView (scrollable)
 *  4. TestSendFooter  — pinned to modal bottom via flex layout (outside scroll region)
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import {
	Modal,
	Button,
	Notice,
	Spinner,
	FormTokenField,
	__experimentalText as Text,
	__experimentalHStack as HStack,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { copy, update } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

import { usePreview } from './use-preview';
import type { PreviewData } from './use-preview';

import './style.scss';

const config: { restNamespace: string } =
	(window as any).prcEmailBuilderConfig ?? {};

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

export function SizeIndicator({ sizeBytes }: { sizeBytes: number }) {
	if (sizeBytes <= 0) return null;

	const kb = sizeBytes / 1024;
	const variant =
		kb >= GMAIL_CLIP_KB ? 'error' : kb >= GMAIL_WARN_KB ? 'warning' : 'ok';

	const label =
		variant === 'error'
			? `${kb.toFixed(1)} KB — Gmail will clip this email`
			: variant === 'warning'
				? `${kb.toFixed(1)} KB — approaching Gmail limit`
				: `${kb.toFixed(1)} KB — within Gmail limits`;

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

	const srcDoc = darkStyle
		? html.includes('</head>')
			? html.replace('</head>', `${darkStyle}</head>`)
			: html.includes('<body')
				? html.replace('<body', `${darkStyle}<body`)
				: `${darkStyle}${html}`
		: html;

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

// ─── TestSendFooter ───────────────────────────────────────────────────────────

const MAX_TEST_RECIPIENTS = 10;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function normalizeEmailToken(token: string): string {
	return token.trim().toLowerCase();
}

function isValidEmailToken(token: string): boolean {
	return EMAIL_PATTERN.test(normalizeEmailToken(token));
}

function TestSendFooter({ postId }: { postId: number }) {
	const [emails, setEmails] = useState<string[]>([]);
	const [sending, setSending] = useState(false);
	const [result, setResult] = useState<'idle' | 'success' | 'error'>('idle');
	const [resultMessage, setResultMessage] = useState('');

	const handleTokensChange = useCallback((tokens: string[]) => {
		const next: string[] = [];
		const seen = new Set<string>();

		for (const token of tokens) {
			const normalized = normalizeEmailToken(token);
			if (!normalized || seen.has(normalized)) {
				continue;
			}
			seen.add(normalized);
			next.push(normalized);
			if (next.length >= MAX_TEST_RECIPIENTS) {
				break;
			}
		}

		setEmails(next);
	}, []);

	const handleSend = useCallback(async () => {
		const recipients = emails.filter(isValidEmailToken);
		if (!recipients.length) return;
		setSending(true);
		setResult('idle');
		try {
			const response = (await apiFetch({
				path: `/${config.restNamespace}/test-send`,
				method: 'POST',
				data: { post_id: postId, emails: recipients },
			})) as {
				success?: boolean;
				sent?: string[];
				failed?: Record<string, string>;
			};

			const sent = Array.isArray(response?.sent)
				? response.sent
				: recipients;
			const failed = response?.failed ?? {};
			const failedAddresses = Object.keys(failed);

			if (failedAddresses.length === 0) {
				setResult('success');
				setResultMessage(
					sprintf(
						/* translators: %s: comma-separated email addresses */
						__('Test email sent to %s.', 'prc-email-builder'),
						sent.join(', ')
					)
				);
			} else if (sent.length > 0) {
				setResult('success');
				setResultMessage(
					sprintf(
						/* translators: 1: sent addresses, 2: failed addresses */
						__(
							'Test email sent to %1$s. Failed for %2$s.',
							'prc-email-builder'
						),
						sent.join(', '),
						failedAddresses.join(', ')
					)
				);
			} else {
				setResult('error');
				setResultMessage(
					sprintf(
						/* translators: %s: failed addresses */
						__(
							'Failed to send test email to %s.',
							'prc-email-builder'
						),
						failedAddresses.join(', ')
					)
				);
			}
		} catch (err: any) {
			setResult('error');
			setResultMessage(
				err?.message ??
					__('Failed to send test email.', 'prc-email-builder')
			);
		} finally {
			setSending(false);
		}
	}, [emails, postId]);

	const hasValidRecipient = emails.some(isValidEmailToken);

	return (
		<div className="prc-email-preview__test-footer">
			<span className="prc-email-preview__test-label">
				{__('SEND TEST EMAIL TO', 'prc-email-builder')}
			</span>
			<div className="prc-email-preview__test-row">
				<FormTokenField
					__nextHasNoMarginBottom
					label={__('Send test email to:', 'prc-email-builder')}
					value={emails}
					onChange={handleTokensChange}
					placeholder={__(
						'you@example.com, teammate@example.com',
						'prc-email-builder'
					)}
					tokenizeOnBlur
					maxLength={MAX_TEST_RECIPIENTS}
				/>
				<Button
					variant="primary"
					onClick={handleSend}
					isBusy={sending}
					disabled={sending || !hasValidRecipient}
				>
					{'▶ '}
					{__('Send test', 'prc-email-builder')}
				</Button>
			</div>
			{result === 'success' && (
				<Notice status="success" isDismissible={false}>
					{resultMessage}
				</Notice>
			)}
			{result === 'error' && (
				<Notice status="error" isDismissible={false}>
					{resultMessage}
				</Notice>
			)}
		</div>
	);
}

// ─── Main modal ──────────────────────────────────────────────────────────────

interface PreviewModalProps {
	postId: number;
	onClose: () => void;
}

export function PreviewModal({ postId, onClose }: PreviewModalProps) {
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
				<TestSendFooter postId={postId} />
			</div>
		</Modal>
	);
}
