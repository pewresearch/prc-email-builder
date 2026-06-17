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
	 * Fetch site content guidelines for SEO-style metadata generation.
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
