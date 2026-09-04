export type JobId = string & { readonly __brand: 'JobId' };
export type VerificationMode = 'verified' | 'unverified' | 'all';

export interface DomainContainsQuery {
	readonly domainContains: string;
	readonly verification: VerificationMode;
}

interface JobViewBase {
	readonly jobId: JobId;
	readonly query: DomainContainsQuery;
	readonly label: string;
}

export interface JobViewQueued extends JobViewBase {
	readonly phase: 'queued';
}

export interface JobViewScanning extends JobViewBase {
	readonly phase: 'scanning';
	readonly scannedUsers: number | null;
	readonly matchedUsers: number | null;
}

export interface AudienceRef {
	readonly key: string;
	readonly label: string;
	readonly count: number;
	readonly verification: VerificationMode;
	readonly builtAt: string;
}

export type DraftOutcome =
	| { readonly status: 'not_requested' }
	| {
			readonly status: 'created';
			readonly postId: number;
			readonly editUrl: string;
	  }
	| { readonly status: 'failed'; readonly message: string };

export interface JobViewReady extends JobViewBase {
	readonly phase: 'ready';
	readonly audience: AudienceRef;
	readonly draft: DraftOutcome;
}

export interface JobViewFailed extends JobViewBase {
	readonly phase: 'failed';
	readonly error: {
		readonly code:
			| 'invalid_query'
			| 'enqueue_failed'
			| 'scan_failed'
			| 'artifact_invalid'
			| 'verification_mismatch'
			| 'timed_out';
		readonly message: string;
	};
}

export type JobView =
	| JobViewQueued
	| JobViewScanning
	| JobViewReady
	| JobViewFailed;

export interface StartAudienceInput {
	readonly domainContains: string;
	readonly verification: VerificationMode;
	readonly label: string;
}

export function isDomainNeedleValid(needle: string): boolean {
	return /^[a-z0-9][a-z0-9.-]{1,62}$/i.test(needle.trim());
}

export function canStartAudience(input: StartAudienceInput): boolean {
	return (
		isDomainNeedleValid(input.domainContains) &&
		input.label.trim().length > 0
	);
}

export function shouldPollAudience(view: JobView | null): boolean {
	if (view === null) {
		return false;
	}

	switch (view.phase) {
		case 'queued':
		case 'scanning':
			return true;
		case 'ready':
		case 'failed':
			return false;
		default: {
			const exhaustive: never = view;
			return exhaustive;
		}
	}
}
