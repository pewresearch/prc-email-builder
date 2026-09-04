/**
 * Parse a CSV (or newline) list of recipient email addresses.
 */

export const CSV_MAX_BYTES = 2_097_152;
export const CSV_MAX_EMAILS = 25_000;

export interface ParsedCsvEmails {
	readonly emails: string[];
	readonly skipped: number;
}

export interface CsvParseFailure {
	readonly error: string;
}

const EMAIL_HEADER_NAMES = new Set(['email', 'e mail', 'email address']);
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function parseCsvEmails(csv: string): ParsedCsvEmails | CsvParseFailure {
	if (csv.length > CSV_MAX_BYTES) {
		return { error: 'The CSV file is larger than 2 MB.' };
	}

	const normalized = csv.replace(/^\uFEFF/, '').replace(/\r\n|\r/g, '\n');
	if (normalized.trim() === '') {
		return { error: 'The CSV file is empty.' };
	}

	const rows: string[][] = [];
	for (const line of normalized.split('\n')) {
		if (line.trim() === '') {
			continue;
		}
		rows.push(parseCsvLine(line));
	}

	if (rows.length === 0) {
		return { error: 'The CSV file is empty.' };
	}

	const headerColumn = emailColumnIndex(rows[0]);
	let start = 0;
	let column: number | null = headerColumn;
	if (headerColumn !== null) {
		start = 1;
	} else if (!rowHasAt(rows[0])) {
		start = 1;
		column = 0;
	}

	const emails: string[] = [];
	const seen = new Set<string>();
	let skipped = 0;

	for (let i = start; i < rows.length; i++) {
		const cells = column !== null ? [rows[i][column] ?? ''] : rows[i];
		for (const cell of cells) {
			if (cell.trim() === '') {
				continue;
			}
			for (const token of cell.split(/[;\s]+/).filter(Boolean)) {
				const email = token.trim().toLowerCase();
				if (email === '') {
					continue;
				}
				if (!EMAIL_PATTERN.test(email)) {
					skipped += 1;
					continue;
				}
				if (seen.has(email)) {
					continue;
				}
				if (seen.size >= CSV_MAX_EMAILS) {
					return {
						error: 'The CSV file has more than 25,000 unique email addresses.',
					};
				}
				seen.add(email);
				emails.push(email);
			}
		}
	}

	if (emails.length === 0) {
		return {
			error: 'The CSV file does not contain any valid email addresses.',
		};
	}

	return { emails, skipped };
}

export function isCsvParseFailure(
	value: ParsedCsvEmails | CsvParseFailure
): value is CsvParseFailure {
	return 'error' in value;
}

export function canStartCsvUpload(
	label: string,
	parsed: ParsedCsvEmails | CsvParseFailure | null
): boolean {
	if (label.trim() === '' || parsed === null || isCsvParseFailure(parsed)) {
		return false;
	}
	return parsed.emails.length > 0;
}

function emailColumnIndex(header: string[]): number | null {
	for (let index = 0; index < header.length; index++) {
		const name = header[index].trim().toLowerCase().replace(/[_-]/g, ' ');
		if (EMAIL_HEADER_NAMES.has(name)) {
			return index;
		}
	}
	return null;
}

function rowHasAt(row: string[]): boolean {
	return row.some((cell) => cell.includes('@'));
}

function parseCsvLine(line: string): string[] {
	const cells: string[] = [];
	let current = '';
	let inQuotes = false;

	for (let i = 0; i < line.length; i++) {
		const char = line[i];
		if (char === '"') {
			if (inQuotes && line[i + 1] === '"') {
				current += '"';
				i += 1;
				continue;
			}
			inQuotes = !inQuotes;
			continue;
		}
		if (char === ',' && !inQuotes) {
			cells.push(current);
			current = '';
			continue;
		}
		current += char;
	}
	cells.push(current);
	return cells;
}
