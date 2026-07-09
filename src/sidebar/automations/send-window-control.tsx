/**
 * Reusable send-window editor (timezone + time-of-day) with an optional
 * "use default" toggle that clears the window (falls back up the cascade).
 */

import { __ } from '@wordpress/i18n';
import {
	SelectControl,
	__experimentalNumberControl as NumberControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import type { SendWindow } from './types';

const TEXT_DOMAIN = 'prc-email-builder';

const TIMEZONE_OPTIONS = [
	{ value: 'America/New_York', label: 'Eastern (America/New_York)' },
	{ value: 'America/Chicago', label: 'Central (America/Chicago)' },
	{ value: 'America/Denver', label: 'Mountain (America/Denver)' },
	{ value: 'America/Los_Angeles', label: 'Pacific (America/Los_Angeles)' },
	{ value: 'UTC', label: 'UTC' },
];

const DEFAULT_WINDOW: SendWindow = {
	timezone: 'America/New_York',
	hour: 9,
	minute: 0,
};

interface SendWindowControlProps {
	/** Current window value, or null when inheriting. */
	value: SendWindow | null;
	/** Called with a window object or null (to inherit). */
	onChange: (value: SendWindow | null) => void;
	/** Label for the "use default" toggle. */
	inheritLabel: string;
	/** Help text shown under the inherit toggle. */
	inheritHelp?: string;
}

export function SendWindowControl({
	value,
	onChange,
	inheritLabel,
	inheritHelp,
}: SendWindowControlProps) {
	const usesDefault = value === null;
	const win = value ?? DEFAULT_WINDOW;

	return (
		<VStack spacing={3}>
			<ToggleControl
				__nextHasNoMarginBottom
				label={inheritLabel}
				help={inheritHelp}
				checked={usesDefault}
				onChange={(checked) =>
					onChange(checked ? null : { ...DEFAULT_WINDOW })
				}
			/>

			{!usesDefault && (
				<>
					<SelectControl
						__nextHasNoMarginBottom
						label={__('Timezone', TEXT_DOMAIN)}
						value={win.timezone}
						options={TIMEZONE_OPTIONS}
						onChange={(timezone) => onChange({ ...win, timezone })}
					/>
					<HStack spacing={2} alignment="left">
						<NumberControl
							__next40pxDefaultSize
							label={__('Hour (0–23)', TEXT_DOMAIN)}
							min={0}
							max={23}
							value={win.hour}
							onChange={(next) =>
								onChange({
									...win,
									hour: clamp(Number(next), 0, 23),
								})
							}
						/>
						<NumberControl
							__next40pxDefaultSize
							label={__('Minute (0–59)', TEXT_DOMAIN)}
							min={0}
							max={59}
							value={win.minute}
							onChange={(next) =>
								onChange({
									...win,
									minute: clamp(Number(next), 0, 59),
								})
							}
						/>
					</HStack>
				</>
			)}
		</VStack>
	);
}

function clamp(value: number, min: number, max: number): number {
	if (Number.isNaN(value)) {
		return min;
	}
	return Math.min(max, Math.max(min, value));
}
