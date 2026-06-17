import { createRoot } from '@wordpress/element';
import EmailLibrary from './email-library';

document.addEventListener('DOMContentLoaded', () => {
	const container = document.getElementById(
		'prc-email-builder-library-admin'
	);
	if (container) {
		const root = createRoot(container);
		root.render(<EmailLibrary />);
	}
});
