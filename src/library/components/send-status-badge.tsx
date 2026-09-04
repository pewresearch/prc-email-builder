import { StatusDotBadge, STATUS_DOT_COLORS } from '@prc/components';

import type { SendStatusTone } from '../utils/send-status';

interface SendStatusBadgeProps {
	label: string;
	tone: SendStatusTone;
}

export default function SendStatusBadge({ label, tone }: SendStatusBadgeProps) {
	const color = STATUS_DOT_COLORS[tone] ?? STATUS_DOT_COLORS.neutral;

	return (
		<StatusDotBadge
			label={label}
			color={color}
			className={`prc-email-send-status prc-email-send-status--${tone}`}
		/>
	);
}
