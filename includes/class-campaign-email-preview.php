<?php
/**
 * Campaign email preview block registration.
 *
 * @package    PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

/**
 * Registers the prc-email-builder/campaign-email-preview dynamic block.
 *
 * Rendering is handled by build/campaign-email-preview/render.php via block.json.
 */
class Campaign_Email_Preview {

	const BLOCK_NAME = 'prc-email-builder/campaign-email-preview';

	/**
	 * Construct.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'block_init' );
	}

	/**
	 * Register the block from built metadata.
	 *
	 * @hook init
	 */
	public function block_init(): void {
		$block_dir = PRC_EMAIL_BUILDER_DIR . '/build/campaign-email-preview';
		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type_from_metadata( $block_dir );
	}
}
