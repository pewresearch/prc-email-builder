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
	plugins: baseConfig.plugins
		.filter(Boolean)
		.filter((plugin) => plugin.constructor.name !== 'CopyPlugin'),
};
