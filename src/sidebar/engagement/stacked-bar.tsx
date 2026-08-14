import {
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';

import type { NumericSegment } from './engagement-model';

export interface StackedBarSegment extends NumericSegment {
	label: string;
	displayValue: string;
}

export interface StackedBarProps {
	title: string;
	description: string;
	emptyMessage: string;
	segments: readonly StackedBarSegment[];
}

function formatPercent(fraction: number): string {
	return `${(fraction * 100).toFixed(1)}%`;
}

export function StackedBar({
	title,
	description,
	emptyMessage,
	segments,
}: StackedBarProps) {
	const total = segments.reduce((sum, segment) => sum + segment.value, 0);
	const positiveSegments = segments.filter((segment) => segment.value > 0);

	return (
		<figure className="prc-email-engagement-chart">
			<VStack spacing={1}>
				<p className="prc-email-engagement-section-title">{title}</p>
				<p className="prc-email-engagement-chart__description">
					{description}
				</p>
				{total <= 0 ? (
					<>
						<div
							className="prc-email-engagement-chart__track prc-email-engagement-chart__track--empty"
							aria-hidden="true"
						/>
						<Text variant="muted">{emptyMessage}</Text>
					</>
				) : (
					<>
						<div
							className="prc-email-engagement-chart__track"
							aria-hidden="true"
						>
							{positiveSegments.map((segment) => (
								<div
									key={segment.id}
									className={`prc-email-engagement-chart__segment prc-email-engagement-chart__segment--${segment.id}`}
									style={{ flexGrow: segment.value }}
								/>
							))}
						</div>
						<ul className="prc-email-engagement-chart__legend">
							{segments.map((segment) => (
								<li
									key={segment.id}
									className="prc-email-engagement-chart__legend-item"
								>
									<span
										className={`prc-email-engagement-chart__swatch prc-email-engagement-chart__swatch--${segment.id}`}
										aria-hidden="true"
									/>
									<span className="prc-email-engagement-chart__legend-label">
										{segment.label}
									</span>
									<span className="prc-email-engagement-chart__legend-value">
										{segment.displayValue} (
										{formatPercent(segment.fraction)})
									</span>
								</li>
							))}
						</ul>
					</>
				)}
			</VStack>
		</figure>
	);
}
