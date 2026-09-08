import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	__experimentalText as Text,
} from '@wordpress/components';

const FULL_WIDTH_STYLE = {
	width: '100%',
	justifyContent: 'center',
} as const;

export function delayMinutesFromSeconds(seconds: number): number {
	return Math.max(1, Math.round(seconds / 60));
}

function formatCountdown(secondsLeft: number): string {
	const minutes = Math.floor(secondsLeft / 60);
	const seconds = secondsLeft % 60;
	return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

function remainingSeconds(pendingSendAt: number, nowMs: number): number {
	return Math.max(0, pendingSendAt - Math.floor(nowMs / 1000));
}

interface DelayedSendStatusProps {
	pendingSendAt: number;
	isCancelling: boolean;
	isSendingImmediately: boolean;
	onCancel: () => void;
	onSendImmediately: () => void;
}

export function DelayedSendStatus({
	pendingSendAt,
	isCancelling,
	isSendingImmediately,
	onCancel,
	onSendImmediately,
}: DelayedSendStatusProps) {
	const [nowMs, setNowMs] = useState(() => Date.now());
	const busy = isCancelling || isSendingImmediately;

	useEffect(() => {
		const timer = setInterval(() => {
			setNowMs(Date.now());
		}, 1000);
		return () => {
			clearInterval(timer);
		};
	}, []);

	const secondsLeft = remainingSeconds(pendingSendAt, nowMs);

	return (
		<>
			<Notice status="info" isDismissible={false}>
				{__(
					'Mailchimp send is queued. Cancel to keep this as a WordPress draft, or send immediately to skip the wait.',
					'prc-email-builder'
				)}
			</Notice>
			<Text>
				{sprintf(
					/* translators: %s: remaining time as m:ss */
					__(
						'Sending in %s. You can cancel until then.',
						'prc-email-builder'
					),
					formatCountdown(secondsLeft)
				)}
			</Text>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				isDestructive
				onClick={onCancel}
				disabled={busy}
				isBusy={isCancelling}
				style={FULL_WIDTH_STYLE}
			>
				{__('Cancel send', 'prc-email-builder')}
			</Button>
			<Button
				__next40pxDefaultSize
				variant="primary"
				onClick={onSendImmediately}
				disabled={busy}
				isBusy={isSendingImmediately}
				style={FULL_WIDTH_STYLE}
			>
				{__('Send immediately', 'prc-email-builder')}
			</Button>
		</>
	);
}
