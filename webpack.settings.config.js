const path = require('path');
const baseConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
	...baseConfig,
	entry: {
		index: path.resolve(__dirname, 'src/settings/index.tsx'),
	},
	output: {
		...baseConfig.output,
		path: path.resolve(__dirname, 'build/settings'),
	},
	plugins: baseConfig.plugins
		.filter(Boolean)
		.filter((plugin) => plugin.constructor.name !== 'CopyPlugin'),
};
