// The root tsconfig uses `moduleResolution: node`, which ignores the package
// `exports` map, so the WordPress build entry needs its types re-exported.
declare module '@wordpress/dataviews/wp' {
	export * from '@wordpress/dataviews';
}
