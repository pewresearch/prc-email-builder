import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	__experimentalText as Text,
} from '@wordpress/components';

import { formatCountdown, remainingSeconds } from './delayed-send-countdown';

const FULL_WIDTH_STYLE = {
	width: '100%',
	justifyContent: 'center',
} as const;

export function delayMinutesFromSeconds(seconds: number): number {
	return Math.max(1, Math.round(seconds / 60));
}

export function useDelayedSendCountdown(pendingSendAt: number): number {
	const [, setTick] = useState(0);

	useEffect(() => {
		if (pendingSendAt <= 0) {
			return;
		}
		let timer: ReturnType<typeof setTimeout>;
		// Tick on the wall-clock second so every mounted countdown (editor
		// notice and Campaign Setup) flips together instead of drifting apart.
		const scheduleTick = () => {
			timer = setTimeout(
				() => {
					setTick((tick) => tick + 1);
					scheduleTick();
				},
				1000 - (Date.now() % 1000)
			);
		};
		scheduleTick();
		return () => {
			clearTimeout(timer);
		};
	}, [pendingSendAt]);

	return remainingSeconds(pendingSendAt, Date.now());
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
	const secondsLeft = useDelayedSendCountdown(pendingSendAt);
	const busy = isCancelling || isSendingImmediately;

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
