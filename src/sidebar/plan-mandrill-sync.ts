export const MANDRILL_TERMINAL_STATUSES = new Set([
	'sent',
	'partial',
	'failed',
	'needs_review',
]);

export interface MandrillServerMeta {
	prc_email_mandrill_send_status?: string;
	prc_email_mandrill_send_summary?: string;
}

export interface MandrillSyncPlan {
	stop: boolean;
	patch: MandrillServerMeta | null;
}

export interface MandrillPollCadence {
	intervalMs: number;
	maxAttempts: number;
}

/**
 * How often, and for how long, the editor polls the server.
 *
 * A running send polls fast and stops after two minutes. A needs_review send
 * waits on a webhook or a retry-batch run that can come hours later, so it
 * polls slowly and does not stop.
 *
 * @param watched Status the editor shows now.
 */
export function mandrillPollCadence(watched: string): MandrillPollCadence {
	if ('needs_review' === watched) {
		return { intervalMs: 30000, maxAttempts: Number.POSITIVE_INFINITY };
	}
	return { intervalMs: 3000, maxAttempts: 40 };
}

/**
 * Decide what the editor takes from the server while a Mandrill send runs.
 *
 * A new terminal status is applied, with the summary, and ends the polling.
 * A summary that changed under an unchanged status is applied too. A webhook
 * that confirms some batches of a needs_review send changes the count and not
 * the status. Polling goes on in that case.
 *
 * @param meta           Meta the server returned.
 * @param watched        Status the editor shows now.
 * @param watchedSummary Raw summary the editor shows now.
 */
export function planMandrillSync(
	meta: MandrillServerMeta | undefined,
	watched: string,
	watchedSummary: string
): MandrillSyncPlan {
	const status = meta?.prc_email_mandrill_send_status ?? '';
	const summary = meta?.prc_email_mandrill_send_summary ?? '';

	if (!status) {
		return { stop: false, patch: null };
	}

	if (status !== watched) {
		if (!MANDRILL_TERMINAL_STATUSES.has(status)) {
			return { stop: false, patch: null };
		}
		return {
			stop: true,
			patch: {
				prc_email_mandrill_send_status: status,
				...(summary
					? { prc_email_mandrill_send_summary: summary }
					: {}),
			},
		};
	}

	if (summary && summary !== watchedSummary) {
		return {
			stop: false,
			patch: { prc_email_mandrill_send_summary: summary },
		};
	}

	return { stop: false, patch: null };
}
