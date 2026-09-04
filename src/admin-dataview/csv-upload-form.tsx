import {
	Button,
	FormFileUpload,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { upload } from '@wordpress/icons';

import {
	canStartCsvUpload,
	isCsvParseFailure,
	parseCsvEmails,
	type ParsedCsvEmails,
	type CsvParseFailure,
} from './csv-email-list';

export interface CsvUploadInput {
	readonly label: string;
	readonly csv: string;
	readonly fileName: string;
}

export function CsvUploadForm({
	input,
	onChange,
}: {
	readonly input: CsvUploadInput;
	readonly onChange: (next: CsvUploadInput) => void;
}) {
	const [parseError, setParseError] = useState<string | null>(null);
	const parsed = input.csv === '' ? null : parseCsvEmails(input.csv);

	return (
		<VStack spacing={4}>
			<TextControl
				__next40pxDefaultSize
				label={__('Audience name', 'prc-email-builder')}
				help={__(
					'This name appears in the recipient list picker.',
					'prc-email-builder'
				)}
				value={input.label}
				onChange={(label) => onChange({ ...input, label })}
				__nextHasNoMarginBottom
			/>
			<FormFileUpload
				accept=".csv,.txt,text/csv,text/plain"
				onChange={(event) => {
					const file = event.currentTarget.files?.[0];
					event.currentTarget.value = '';
					if (!file) {
						return;
					}
					const reader = new window.FileReader();
					reader.onload = () => {
						const csv = String(reader.result ?? '');
						const next = parseCsvEmails(csv);
						if (isCsvParseFailure(next)) {
							setParseError(next.error);
							onChange({
								...input,
								csv: '',
								fileName: file.name,
							});
							return;
						}
						setParseError(null);
						onChange({
							...input,
							csv,
							fileName: file.name,
						});
					};
					reader.onerror = () => {
						setParseError(
							__(
								'Could not read the selected file.',
								'prc-email-builder'
							)
						);
						onChange({ ...input, csv: '', fileName: '' });
					};
					reader.readAsText(file);
				}}
				render={({ openFileDialog }) => (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						icon={upload}
						onClick={openFileDialog}
					>
						{input.fileName
							? input.fileName
							: __('Choose CSV file', 'prc-email-builder')}
					</Button>
				)}
			/>
			<p className="components-base-control__help">
				{__(
					'Use a column named email, or one address per row. Invalid addresses are skipped.',
					'prc-email-builder'
				)}
			</p>
			{parseError ? <p role="alert">{parseError}</p> : null}
			<CsvPreview parsed={parsed} />
		</VStack>
	);
}

export { canStartCsvUpload };

function CsvPreview({
	parsed,
}: {
	readonly parsed: ParsedCsvEmails | CsvParseFailure | null;
}) {
	if (parsed === null) {
		return null;
	}
	if (isCsvParseFailure(parsed)) {
		return <p role="alert">{parsed.error}</p>;
	}

	return (
		<p>
			{sprintf(
				/* translators: 1: valid email count, 2: skipped row count */
				__('%1$s valid addresses. %2$s skipped.', 'prc-email-builder'),
				parsed.emails.length.toLocaleString(),
				parsed.skipped.toLocaleString()
			)}
		</p>
	);
}
