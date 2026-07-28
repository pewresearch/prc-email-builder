const path = require('path');
const { getWebpackEntryPoints } = require('@wordpress/scripts/utils');
const config = require('../../webpack.config');

module.exports = {
	...config,
	entry: {
		// Auto-discover src/**/block.json entries (campaign-email-preview,
		// latest-campaign-query shim, future blocks).
		...getWebpackEntryPoints('script')(),
		// Explicit non-block editor apps.
		'sidebar/index': path.resolve(__dirname, 'src/sidebar/index.tsx'),
		'form-action/index': path.resolve(
			__dirname,
			'src/form-action/index.ts'
		),
		'term-admin/index': path.resolve(__dirname, 'src/term-admin/index.ts'),
	},
};
