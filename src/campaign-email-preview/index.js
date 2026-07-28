/**
 * WordPress Dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { mobile } from '@wordpress/icons';

/**
 * Internal Dependencies
 */
import './style.scss';
import edit from './edit';
import metadata from './block.json';

registerBlockType(metadata.name, {
	...metadata,
	icon: mobile,
	edit,
	save: () => null,
});
