const path = require('path');
const config = require('../../webpack.config');

module.exports = {
	...config,
	entry: {
		'sidebar/index': path.resolve(__dirname, 'src/sidebar/index.tsx'),
		'form-action/index': path.resolve(
			__dirname,
			'src/form-action/index.ts'
		),
		'term-admin/index': path.resolve(__dirname, 'src/term-admin/index.ts'),
	},
};
