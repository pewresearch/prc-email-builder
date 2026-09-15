import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback } from '@wordpress/element';
import { Button, Notice, FormTokenField } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

const config: { restNamespace: string } =
	(window as any).prcEmailBuilderConfig ?? {};

const MAX_TEST_RECIPIENTS = 10;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function normalizeEmailToken(token: string): string {
	return token.trim().toLowerCase();
}

function isValidEmailToken(token: string): boolean {
	return EMAIL_PATTERN.test(normalizeEmailToken(token));
}

interface TestSendFooterProps {
	postId: number;
	testEmails: string[];
	onTestEmailsChange: (emails: string[]) => void;
}

export function TestSendFooter({
	postId,
	testEmails,
	onTestEmailsChange,
}: TestSendFooterProps) {
	const [sending, setSending] = useState(false);
	const [result, setResult] = useState<'idle' | 'success' | 'error'>('idle');
	const [resultMessage, setResultMessage] = useState('');

	const handleTokensChange = useCallback(
		(tokens: string[]) => {
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

			onTestEmailsChange(next);
		},
		[onTestEmailsChange]
	);

	const handleSend = useCallback(async () => {
		const recipients = testEmails.filter(isValidEmailToken);
		if (!recipients.length) {
			return;
		}
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
				onTestEmailsChange([]);
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
				onTestEmailsChange([]);
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
	}, [onTestEmailsChange, postId, testEmails]);

	const hasValidRecipient = testEmails.some(isValidEmailToken);

	return (
		<div className="prc-email-preview__test-footer">
			<span className="prc-email-preview__test-label">
				{__('SEND TEST EMAIL TO', 'prc-email-builder')}
			</span>
			<div className="prc-email-preview__test-row">
				<FormTokenField
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={__('Send test email to:', 'prc-email-builder')}
					value={testEmails}
					onChange={handleTokensChange}
					placeholder={__(
						'you@example.com, teammate@example.com',
						'prc-email-builder'
					)}
					tokenizeOnBlur
					maxLength={MAX_TEST_RECIPIENTS}
				/>
				<Button
					__next40pxDefaultSize
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
