<?php
/**
 * Shared helpers for newsletter AI abilities.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI client and content-guidelines helpers used by newsletter abilities.
 */
trait Newsletter_AI_Ability_Helpers {

	/**
	 * Run a callback on the requested target site.
	 *
	 * @param array|null $input    Ability input.
	 * @param callable   $callback Callback to run after site validation/switching.
	 * @return mixed
	 */
	private function with_site( $input, callable $callback ) {
		return \PRC\Platform\AI\Utils\with_site(
			\PRC\Platform\AI\Utils\resolve_site_id( is_array( $input ) ? $input : null ),
			'prc-email-builder/prc-email-builder.php',
			$callback
		);
	}

	/**
	 * Append standard site targeting instructions.
	 *
	 * @param string $instructions Base instructions.
	 * @return string
	 */
	private function with_site_instructions( string $instructions ): string {
		return trim( $instructions ) . ' Optionally pass site_id to run against a specific multisite blog; defaults to the content site (20). If this plugin is inactive on the target site, the ability returns plugin_inactive_on_site.';
	}

	/**
	 * Fetch site content guidelines for SEO-style metadata generation.
	 *
	 * @param int $post_id Post id.
	 */
	private function get_content_guidelines( int $post_id ): string {
		if ( ! function_exists( 'PRC\Platform\AI\Utils\get_content_guidelines_for_post' ) ) {
			return '';
		}

		$result = \PRC\Platform\AI\Utils\get_content_guidelines_for_post(
			$post_id,
			array( 'task' => 'seo_metadata' )
		);
		if ( empty( $result['packet_text'] ) || ! is_string( $result['packet_text'] ) ) {
			return '';
		}

		return trim( $result['packet_text'] );
	}

	/**
	 * Generate plain text from the WP AI client, returning empty string on failure.
	 *
	 * @param string $prompt Prompt.
	 */
	private function generate_text_via_ai_client( string $prompt ): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		$builder = wp_ai_client_prompt( $prompt );
		if ( is_wp_error( $builder ) ) {
			return '';
		}

		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return '';
		}

		return (string) $result;
	}

	/**
	 * Generate JSON text from the WP AI client with system instructions.
	 *
	 * @param string $prompt Prompt.
	 * @param string $system_instruction System instruction.
	 */
	private function generate_json_via_ai_client( string $prompt, string $system_instruction ): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		$builder = wp_ai_client_prompt( $prompt );
		if ( is_wp_error( $builder ) ) {
			return '';
		}

		$builder = $builder->using_system_instruction( $system_instruction );
		$builder = $builder->using_temperature( 0.4 );

		if ( method_exists( $builder, 'as_json_response' ) ) {
			$builder = $builder->as_json_response();
		}

		$result = $builder->generate_text();
		if ( is_wp_error( $result ) ) {
			return '';
		}

		return (string) $result;
	}
}
