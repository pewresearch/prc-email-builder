<?php
/**
 * Suggest Subject ability.
 *
 * Uses AI to generate email subject line options for a newsletter post.
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
 * Registers and executes prc-email-builder/suggest-subject.
 */
class Suggest_Subject_Ability {

	use Newsletter_AI_Ability_Helpers;

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static $ability_name = 'prc-email-builder/suggest-subject';

	/**
	 * @var array<int, string>
	 */
	public static $allowed_blocks = array();

	private const OPTION_COUNT = 3;

	private const MAX_SUBJECT_LENGTH = 78;

	/**
	 * Default system prompt for subject line generation.
	 */
	public static function get_default_system_prompt_template(): string {
		return 'You are an email marketing editor for a research organization. Write clear, factual email subject lines that accurately reflect the newsletter content. Use a neutral, professional tone. Each subject must be at most {{max_length}} characters. Produce exactly {{option_count}} meaningfully different angles.';
	}

	/**
	 * Hard-coded JSON output format instruction.
	 */
	public static function get_output_format_instruction(): string {
		return 'CRITICAL: Return ONLY a JSON array of exactly ' . self::OPTION_COUNT . ' objects. Each object must have:
- "subject" (string, the email subject line)

Do not use markdown fences or extra prose. Example:
[{"subject":"First option"},{"subject":"Second option"},{"subject":"Third option"}]';
	}

	/**
	 * @hook wp_abilities_api_init
	 */
	public function register_ability(): void {
		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Suggest Newsletter Subject', 'prc-email-builder' ),
				'description'         => __( 'Generates three email subject line options from newsletter content.', 'prc-email-builder' ),
				'category'            => 'communication',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'postId'  => array(
							'type'        => 'number',
							'description' => 'Newsletter post ID for context.',
						),
						'context' => array(
							'type'        => 'string',
							'description' => 'Optional extra context for generation.',
						),
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
									'subject' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'suggest_subjects' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'    => array(
						'instructions' => 'Generates three distinct email subject line options from newsletter content.',
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
	 * @param array<string, mixed> $input Input parameters.
	 * @return array<string, mixed>|WP_Error
	 */
	public function suggest_subjects( $input ) {
		$post_id = isset( $input['postId'] ) ? (int) $input['postId'] : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'missing_post_id', __( 'No postId provided.', 'prc-email-builder' ) );
		}

		$context = isset( $input['context'] ) ? sanitize_textarea_field( (string) $input['context'] ) : '';

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_email_post_type( $post->post_type ) ) {
			return new WP_Error( 'post_not_found', __( 'Newsletter post not found.', 'prc-email-builder' ) );
		}

		$title   = $post->post_title;
		$content = wp_strip_all_tags( (string) $post->post_content, true );
		$system  = $this->build_system_instruction( $context, $this->get_content_guidelines( $post_id ) );

		$prompt = wp_sprintf(
			"%s\n\nNewsletter Title: %s\n\nNewsletter Content:\n%s\n\nReturn ONLY a JSON array of exactly %d objects. Each object: {\"subject\":\"...\"}. No markdown fences.",
			$system,
			$title,
			$content,
			self::OPTION_COUNT
		);

		$raw     = trim( $this->generate_text_via_ai_client( $prompt ) );
		$options = $this->parse_subject_options( $raw );

		if ( count( $options ) < self::OPTION_COUNT ) {
			$raw   = trim( $this->generate_text_via_ai_client( $prompt ) );
			$retry = $this->parse_subject_options( $raw );
			if ( count( $retry ) > count( $options ) ) {
				$options = $retry;
			}
		}

		if ( array() === $options ) {
			return new WP_Error( 'no_options', __( 'Could not generate subject options.', 'prc-email-builder' ) );
		}

		$options = array_slice( $options, 0, self::OPTION_COUNT );
		while ( count( $options ) < self::OPTION_COUNT ) {
			$options[] = $options[ count( $options ) - 1 ];
		}

		return array( 'options' => $options );
	}

	private function build_system_instruction( string $extra_context, string $guidelines ): string {
		$text = strtr(
			self::get_default_system_prompt_template(),
			array(
				'{{max_length}}'   => (string) self::MAX_SUBJECT_LENGTH,
				'{{option_count}}' => (string) self::OPTION_COUNT,
			)
		);

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
	 * @return array<int, array{subject: string}>
	 */
	private function parse_subject_options( string $raw ): array {
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
			$subject = '';
			if ( is_string( $item ) ) {
				$subject = trim( $item );
			} elseif ( is_array( $item ) && ! empty( $item['subject'] ) ) {
				$subject = trim( (string) $item['subject'] );
			}

			if ( '' === $subject ) {
				continue;
			}

			if ( mb_strlen( $subject ) > self::MAX_SUBJECT_LENGTH ) {
				$subject = mb_substr( $subject, 0, self::MAX_SUBJECT_LENGTH );
			}

			$out[] = array( 'subject' => $subject );
		}

		return $out;
	}
}
