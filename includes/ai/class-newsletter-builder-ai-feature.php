<?php
/**
 * Newsletter Builder AI feature.
 *
 * Registers inbox metadata AI abilities and exposes ability names to the sidebar script.
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
 * Newsletter Builder AI feature class.
 */
class Email_Builder_AI_Feature extends Abstract_Feature {

	/**
	 * Feature identifier.
	 */
	public static function get_id(): string {
		return 'newsletter-builder-ai';
	}

	/**
	 * Feature metadata.
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Newsletter Builder AI', 'prc-email-builder' ),
			'description' => __( 'Generates email subject lines, preview text, and weekly links newsletters from published content.', 'prc-email-builder' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Register abilities and editor localization when the feature is enabled.
	 */
	public function register(): void {
		$subject  = new Suggest_Subject_Ability();
		$preview  = new Suggest_Preview_Text_Ability();
		$links    = new Generate_Links_Newsletter_Ability();

		add_action( 'wp_abilities_api_init', array( $subject, 'register_ability' ) );
		add_action( 'wp_abilities_api_init', array( $preview, 'register_ability' ) );
		add_action( 'wp_abilities_api_init', array( $links, 'register_ability' ) );

		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_ai_ability_names' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'localize_library_ai_ability_names' ), 20 );
	}

	/**
	 * Expose registered ability names to the sidebar script.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function localize_ai_ability_names(): void {
		$handle = Assets::SCRIPT_HANDLE;

		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'prcEmailBuilderAI',
			array(
				'enabled'            => true,
				'subjectAbilityName' => Suggest_Subject_Ability::$ability_name,
				'previewAbilityName' => Suggest_Preview_Text_Ability::$ability_name,
			)
		);
	}

	/**
	 * Expose the links newsletter ability to the Email Library admin app.
	 *
	 * @hook admin_enqueue_scripts
	 */
	public function localize_library_ai_ability_names( string $hook_suffix ): void {
		if ( Post_Type::CAMPAIGN_POST_TYPE . '_page_' . Library::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$handle = 'prc-email-builder-library';
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'prcEmailBuilderLibraryAI',
			array(
				'enabled'                  => true,
				'linksNewsletterAbilityName' => Generate_Links_Newsletter_Ability::$ability_name,
			)
		);
	}
}
