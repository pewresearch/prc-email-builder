export const EMAIL_SUBJECT_LOCK = 'prc-email-subject';

interface BlankEmailSubject {
	readonly status: 'blank';
}

interface ReadyEmailSubject {
	readonly status: 'ready';
	readonly line: string;
}

export type ParsedEmailSubject = BlankEmailSubject | ReadyEmailSubject;

export function parseEmailSubject(raw: unknown): ParsedEmailSubject {
	if (typeof raw !== 'string') {
		return { status: 'blank' };
	}
	const line = raw.trim();
	if (line === '') {
		return { status: 'blank' };
	}
	return { status: 'ready', line };
}
