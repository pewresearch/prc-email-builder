const path = require('path');
const baseConfig = require('../../webpack.config');

module.exports = {
	...baseConfig,
	entry: {
		index: path.resolve(__dirname, 'src/admin-dataview/index.tsx'),
	},
	output: {
		...baseConfig.output,
		path: path.resolve(__dirname, 'build/admin-dataview'),
	},
	// Reuse the shell's DataForm; this script depends on prc-wp-admin-dataview.
	externals: {
		...(baseConfig.externals || {}),
		'@wordpress/dataviews/wp': 'prcWpAdminDataviewsWp',
	},
	plugins: baseConfig.plugins
		.filter(Boolean)
		.filter((plugin) => plugin.constructor.name !== 'CopyPlugin'),
};
