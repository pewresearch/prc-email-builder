export function formatCountdown(secondsLeft: number): string {
	const minutes = Math.floor(secondsLeft / 60);
	const seconds = secondsLeft % 60;
	return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

export function remainingSeconds(pendingSendAt: number, nowMs: number): number {
	return Math.max(0, pendingSendAt - Math.floor(nowMs / 1000));
}
