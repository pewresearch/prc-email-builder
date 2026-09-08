<?php
/**
 * Suggest Preview Text ability.
 *
 * Uses AI to generate email preview / preheader text options for a newsletter post.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and executes prc-email-builder/suggest-preview-text.
 */
class Suggest_Preview_Text_Ability {

	use Newsletter_AI_Ability_Helpers;

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static $ability_name = 'prc-email-builder/suggest-preview-text';

	/**
	 * Allowed blocks.
	 *
	 * @var array<int, string>
	 */
	public static $allowed_blocks = array();

	private const OPTION_COUNT = 3;

	private const MAX_PREVIEW_LENGTH = 120;

	/**
	 * Default system prompt for preview text generation.
	 */
	public static function get_default_system_prompt_template(): string {
		return 'You are an email marketing editor for a research organization. Write preview text (preheader) that complements the subject line and entices opens without repeating the subject verbatim. Use a neutral, professional tone. Each preview must be at most {{max_length}} characters. Produce exactly {{option_count}} meaningfully different angles.';
	}

	/**
	 * Hard-coded JSON output format instruction.
	 */
	public static function get_output_format_instruction(): string {
		return 'CRITICAL: Return ONLY a JSON array of exactly ' . self::OPTION_COUNT . ' objects. Each object must have:
- "previewText" (string, the email preview / preheader text)

Do not use markdown fences or extra prose. Example:
[{"previewText":"First option"},{"previewText":"Second option"},{"previewText":"Third option"}]';
	}

	/**
	 * Hook callback for @hook.
	 *
	 * @hook wp_abilities_api_init
	 */
	public function register_ability(): void {
		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Suggest Newsletter Preview Text', 'prc-email-builder' ),
				'description'         => __( 'Generates three email preview text options from newsletter content.', 'prc-email-builder' ),
				'category'            => 'communication',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'postId'         => array(
							'type'        => 'number',
							'description' => 'Newsletter post ID for context.',
						),
						'currentSubject' => array(
							'type'        => 'string',
							'description' => 'Current subject line so preview text complements it.',
						),
						'context'        => array(
							'type'        => 'string',
							'description' => 'Optional extra context for generation.',
						),
						'site_id'        => \PRC\Platform\AI\Utils\site_id_input_schema_property(),
					),
					'required'             => array( 'postId' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'options' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'previewText' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => function ( $input ) {
					return $this->with_site(
						$input,
						function () use ( $input ) {
							return $this->suggest_preview_text( $input );
						}
					);
				},
				'permission_callback' => function ( $input = null ) {
					return $this->with_site(
						$input,
						function () {
							return current_user_can( 'edit_posts' );
						}
					);
				},
				'meta'                => array(
					'annotations'    => array(
						'instructions' => $this->with_site_instructions( 'Generates three distinct email preview text options that complement the subject line.' ),
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => false,
					),
					'show_in_rest'   => true,
					'allowed_blocks' => self::$allowed_blocks,
					'mcp'            => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Suggest preview text.
	 *
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public function suggest_preview_text( $input ) {
		$post_id = isset( $input['postId'] ) ? (int) $input['postId'] : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'No postId provided.', 'prc-email-builder' ) );
		}

		$current_subject = isset( $input['currentSubject'] ) ? sanitize_text_field( (string) $input['currentSubject'] ) : '';
		$context         = isset( $input['context'] ) ? sanitize_textarea_field( (string) $input['context'] ) : '';

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_email_post_type( $post->post_type ) ) {
			return new WP_Error( 'post_not_found', __( 'Newsletter post not found.', 'prc-email-builder' ) );
		}

		$title   = $post->post_title;
		$content = wp_strip_all_tags( (string) $post->post_content, true );
		$system  = $this->build_system_instruction( $context, $current_subject, $this->get_content_guidelines( $post_id ) );

		$subject_block = '' !== $current_subject
			? "\n\nCurrent Subject Line: {$current_subject}"
			: '';

		$prompt = wp_sprintf(
			"%s\n\nNewsletter Title: %s%s\n\nNewsletter Content:\n%s\n\nReturn ONLY a JSON array of exactly %d objects. Each object: {\"previewText\":\"...\"}. No markdown fences.",
			$system,
			$title,
			$subject_block,
			$content,
			self::OPTION_COUNT
		);

		$raw     = trim( $this->generate_text_via_ai_client( $prompt ) );
		$options = $this->parse_preview_options( $raw );

		if ( count( $options ) < self::OPTION_COUNT ) {
			$raw   = trim( $this->generate_text_via_ai_client( $prompt ) );
			$retry = $this->parse_preview_options( $raw );
			if ( count( $retry ) > count( $options ) ) {
				$options = $retry;
			}
		}

		if ( array() === $options ) {
			return new WP_Error( 'no_options', __( 'Could not generate preview text options.', 'prc-email-builder' ) );
		}

		$options      = array_slice( $options, 0, self::OPTION_COUNT );
		$option_count = count( $options );
		while ( $option_count < self::OPTION_COUNT ) {
			$options[] = $options[ $option_count - 1 ];
			++$option_count;
		}

		return array( 'options' => $options );
	}

	/**
	 * Build system instruction.
	 *
	 * @param string $extra_context Extra context.
	 * @param string $current_subject Current subject.
	 * @param string $guidelines Guidelines.
	 */
	private function build_system_instruction( string $extra_context, string $current_subject, string $guidelines ): string {
		$text = strtr(
			self::get_default_system_prompt_template(),
			array(
				'{{max_length}}'   => (string) self::MAX_PREVIEW_LENGTH,
				'{{option_count}}' => (string) self::OPTION_COUNT,
			)
		);

		if ( '' !== $current_subject ) {
			$text .= "\n\nThe current subject line is: \"" . $current_subject . '". Preview text must complement it without repeating it.';
		}

		if ( '' !== $extra_context ) {
			$text .= "\n\nAdditional context from the editor:\n" . $extra_context;
		}

		if ( '' !== $guidelines ) {
			$text .= "\n\nSITE CONTENT GUIDELINES (authoritative):\n\n" . $guidelines;
		}

		$text .= "\n\n" . self::get_output_format_instruction();

		return $text;
	}

	/**
	 * Parse preview options.
	 *
	 * @param string $raw Raw.
	 * @return array<int, array{previewText: string}>
	 */
	private function parse_preview_options( string $raw ): array {
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/', '', $raw );
		if ( preg_match( '/\[.*\]/s', (string) $raw, $matches ) ) {
			$raw = $matches[0];
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $item ) {
			$preview = '';
			if ( is_string( $item ) ) {
				$preview = trim( $item );
			} elseif ( is_array( $item ) && ! empty( $item['previewText'] ) ) {
				$preview = trim( (string) $item['previewText'] );
			}

			if ( '' === $preview ) {
				continue;
			}

			if ( mb_strlen( $preview ) > self::MAX_PREVIEW_LENGTH ) {
				$preview = mb_substr( $preview, 0, self::MAX_PREVIEW_LENGTH );
			}

			$out[] = array( 'previewText' => $preview );
		}

		return $out;
	}
}
