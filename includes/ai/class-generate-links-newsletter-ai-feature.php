<?php
/**
 * Generate Links Newsletter AI feature.
 *
 * Registers the weekly links newsletter ability and exposes it to the
 * Campaigns DataViews library script when the feature is enabled.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Links Newsletter AI feature class.
 */
class Generate_Links_Newsletter_AI_Feature extends Abstract_Feature {

	/**
	 * Feature identifier.
	 */
	public static function get_id(): string {
		return 'generate-links-newsletter-ai';
	}

	/**
	 * Feature metadata.
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Generate Links Newsletter', 'prc-email-builder' ),
			'description' => __( 'Generates a weekly links newsletter draft from published content on the Campaigns library page.', 'prc-email-builder' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Register the ability and library localization when the feature is enabled.
	 */
	public function register(): void {
		$links = new Generate_Links_Newsletter_Ability();

		add_action( 'wp_abilities_api_init', array( $links, 'register_ability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'localize_library_ai_ability_names' ), 20 );
	}

	/**
	 * Expose the links newsletter ability to the Campaigns DataViews admin app.
	 *
	 * @hook admin_enqueue_scripts
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function localize_library_ai_ability_names( string $hook_suffix ): void {
		if ( Post_Type::CAMPAIGN_POST_TYPE . '_page_' . Email_Lists::CAMPAIGNS_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$handle = Email_Lists::SCRIPT_HANDLE;
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'prcEmailBuilderLibraryAI',
			array(
				'enabled'                    => true,
				'linksNewsletterAbilityName' => Generate_Links_Newsletter_Ability::$ability_name,
			)
		);
	}
}
