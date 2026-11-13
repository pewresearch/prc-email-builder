import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

export type MandrillSendSummary = Record<string, number | string> | null;

export function getMandrillNoticeStatus(
	status: string
): 'success' | 'warning' | 'error' | 'info' {
	if (status === 'sent') {
		return 'success';
	}
	if (status === 'partial' || status === 'needs_review') {
		return 'warning';
	}
	if (status === 'failed') {
		return 'error';
	}
	return 'info';
}

interface MandrillSendStatusNoticeProps {
	status: string;
	summary: MandrillSendSummary;
}

export function MandrillSendStatusNotice({
	status,
	summary,
}: MandrillSendStatusNoticeProps) {
	if (!status) {
		return null;
	}

	if (status === 'needs_review') {
		const batches = Number(summary?.needs_review_batches ?? 0);
		return (
			<Notice
				status="warning"
				isDismissible={false}
				className="prc-email-send-needs-review"
			>
				<strong>
					{sprintf(
						/* translators: %d: number of batches that need review */
						_n(
							'Needs review: %d batch',
							'Needs review: %d batches',
							batches,
							'prc-email-builder'
						),
						batches
					)}
				</strong>
				<br />
				{__(
					'Mandrill never confirmed these batches. They may have been delivered, so they are not sent again. Each moves to accepted when Mandrill reports delivery events for it.',
					'prc-email-builder'
				)}
			</Notice>
		);
	}

	return (
		<Notice status={getMandrillNoticeStatus(status)} isDismissible={false}>
			{sprintf(
				/* translators: %s: send status */
				__('Send status: %s', 'prc-email-builder'),
				status
			)}
		</Notice>
	);
}
