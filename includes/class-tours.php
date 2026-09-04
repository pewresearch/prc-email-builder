<?php
/**
 * Email campaign first-visit tour.
 *
 * @package PRC\Platform\Email_Builder
 */

declare( strict_types=1 );

namespace PRC\Platform\Email_Builder;

/**
 * Soft-depends on prc-wp-admin-tours via the register action.
 */
class Tours {
	public const TOUR_ID = 'prc-email-builder/campaign-first-visit';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'prc_wp_admin_tours_register', $this, 'register_tours' );
	}

	/**
	 * Register the campaign first-visit tour.
	 *
	 * @param object $registry Tour registry.
	 */
	public function register_tours( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$registry->register( self::tour_config() );
	}

	/**
	 * Tour definition owned by this plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function tour_config(): array {
		$list_screen   = array(
			'kind'     => 'pageSlug',
			'pageSlug' => Email_Lists::CAMPAIGNS_PAGE_SLUG,
		);
		$editor_screen = array(
			'kind'     => 'postTypeEditor',
			'postType' => Post_Type::CAMPAIGN_POST_TYPE,
		);

		return array(
			'id'         => self::TOUR_ID,
			'title'      => __( 'Create and send a campaign', 'prc-email-builder' ),
			'version'    => 1,
			'autoStart'  => true,
			'capability' => 'edit_posts',
			'screens'    => array( $list_screen, $editor_screen ),
			'steps'      => array(
				array(
					'id'             => 'email-create-campaign',
					'title'          => __( 'Create a campaign', 'prc-email-builder' ),
					'description'    => __( 'Pick a newsletter list to start from its pattern, or choose Create blank.', 'prc-email-builder' ),
					'selector'       => '[data-prc-tour="email-create-campaign"]',
					'advanceOnClick' => true,
					'side'           => 'bottom',
					'screens'        => array( $list_screen ),
				),
				array(
					'id'              => 'email-pattern-selector',
					'title'           => __( 'Choose a pattern', 'prc-email-builder' ),
					'description'     => __( 'Start from a pattern, or continue with a blank canvas. This step is skipped when you started from a list pattern.', 'prc-email-builder' ),
					'selector'        => '[data-prc-tour="email-pattern-selector"], .prc-email-pattern-selector__modal .dataviews-wrapper',
					'clickOnNext'     => '[data-prc-tour="email-pattern-selector-dismiss"]',
					'advanceWhenGone' => true,
					'side'            => 'bottom',
					'screens'         => array( $editor_screen ),
				),
				array(
					'id'          => 'email-canvas',
					'title'       => __( 'Add content', 'prc-email-builder' ),
					'description' => __( 'Add content in the canvas like any post. Use the inserter to add blocks.', 'prc-email-builder' ),
					'selector'    => null,
					'side'        => 'bottom',
					'screens'     => array( $editor_screen ),
				),
				array(
					'id'          => 'email-preview',
					'title'       => __( 'Preview the inbox', 'prc-email-builder' ),
					'description' => __( 'Open Email Preview from the View menu to check subject, preview text, and the rendered email.', 'prc-email-builder' ),
					'selector'    => '[data-prc-tour="email-preview-menu"], [data-prc-tour="email-preview"], [aria-label*="Email Preview"]',
					'waitMs'      => 8000,
					'side'        => 'bottom',
					'screens'     => array( $editor_screen ),
				),
				array(
					'id'          => 'email-newsletter-list',
					'title'       => __( 'Assign a newsletter list', 'prc-email-builder' ),
					'description' => __( 'Set the newsletter list in Email Settings. Audience and segment then follow that list.', 'prc-email-builder' ),
					'selector'    => '[data-prc-tour="email-newsletter-list"], [data-prc-tour="email-settings"]',
					'side'        => 'left',
					'screens'     => array( $editor_screen ),
				),
				array(
					'id'                       => 'email-send',
					'title'                    => __( 'Campaign Setup', 'prc-email-builder' ),
					'description'              => __( 'Audience and segment live in Campaign Setup. Review them here. Do not send from this tour.', 'prc-email-builder' ),
					'selector'                 => '[data-prc-tour="email-send"], button[aria-label="Campaign Setup"]',
					'side'                     => 'left',
					'disableActiveInteraction' => true,
					'screens'                  => array( $editor_screen ),
				),
				array(
					'id'                       => 'email-publish',
					'title'                    => __( 'Publish can send', 'prc-email-builder' ),
					'description'              => __( 'Publishing can send the campaign automatically when that setting is on. Do not click Publish until you are ready.', 'prc-email-builder' ),
					'selector'                 => '.editor-post-publish-button__button, .editor-post-publish-panel',
					'side'                     => 'bottom',
					'disableActiveInteraction' => true,
					'screens'                  => array( $editor_screen ),
				),
			),
		);
	}
}
