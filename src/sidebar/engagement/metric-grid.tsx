export interface MetricCardModel {
	id: string;
	label: string;
	rawValue: number | undefined;
	displayValue: string;
}

export interface MetricGroupModel {
	id: string;
	title: string | null;
	cards: MetricCardModel[];
}

function MetricCard({ card }: { card: MetricCardModel }) {
	return (
		<div className="prc-email-engagement-card">
			<span className="prc-email-engagement-card__label">
				{card.label}
			</span>
			<span className="prc-email-engagement-card__value">
				{card.displayValue}
			</span>
		</div>
	);
}

export function MetricGrid({ cards }: { cards: readonly MetricCardModel[] }) {
	return (
		<div className="prc-email-engagement-grid">
			{cards.map((card) => (
				<MetricCard key={card.id} card={card} />
			))}
		</div>
	);
}
