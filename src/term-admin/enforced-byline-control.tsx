/**
 * Newsletter List term edit control for the enforced staff byline.
 */

import { createRoot, useCallback, useState } from '@wordpress/element';
import { BYLINE_TAXONOMY, StaffBylinePicker } from '../staff-byline-picker';

interface TermAdminConfig {
	enforcedBylineMetaKey?: string;
	bylineTaxonomy?: string;
}

const config: TermAdminConfig =
	(window as { prcEmailBuilderTermAdmin?: TermAdminConfig })
		.prcEmailBuilderTermAdmin ?? {};

const META_INPUT_ID =
	config.enforcedBylineMetaKey ??
	'prc_newsletter_list_enforced_byline_term_id';
const TAXONOMY = config.bylineTaxonomy ?? BYLINE_TAXONOMY;

function getMetaInput(): HTMLInputElement | null {
	return document.getElementById(META_INPUT_ID) as HTMLInputElement | null;
}

function EnforcedBylineControl() {
	const [termId, setTermId] = useState(() => getMetaInput()?.value ?? '');

	const persistTermId = useCallback((nextId: string) => {
		setTermId(nextId);
		const input = getMetaInput();
		if (input) {
			input.value = nextId;
		}
	}, []);

	return (
		<StaffBylinePicker
			value={termId}
			onChange={persistTermId}
			taxonomy={TAXONOMY}
		/>
	);
}

export function mountEnforcedBylineControl(): void {
	const container = document.getElementById(
		'prc-newsletter-list-enforced-byline-root'
	);
	if (!container) {
		return;
	}

	createRoot(container).render(<EnforcedBylineControl />);
}
