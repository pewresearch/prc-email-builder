<?php
/**
 * Generate Links Newsletter ability.
 *
 * Builds a weekly links-style newsletter draft from published content in the
 * last 7–30 days (default 7), optionally scoped to a research team.
 *
 * @package PRC\Platform\Email_Builder
 */

declare(strict_types=1);

namespace PRC\Platform\Email_Builder;

use WP_Error;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and executes prc-email-builder/generate-links-newsletter.
 */
class Generate_Links_Newsletter_Ability {

	use Newsletter_AI_Ability_Helpers;

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static $ability_name = 'prc-email-builder/generate-links-newsletter';

	/**
	 * Default lookback window in days.
	 */
	private const DEFAULT_LOOKBACK_DAYS = 7;

	/**
	 * Minimum lookback window in days.
	 */
	private const MIN_LOOKBACK_DAYS = 7;

	/**
	 * Maximum lookback window in days.
	 */
	private const MAX_LOOKBACK_DAYS = 30;

	/**
	 * Research teams taxonomy slug.
	 */
	private const RESEARCH_TEAMS_TAXONOMY = 'research-teams';

	/**
	 * Post types scanned for weekly content.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_POST_TYPES = array(
		'post',
		'short-read',
		'feature',
		'fact-sheet',
		'quiz',
		'dataset',
		'decoded',
	);

	/**
	 * @var array<int, string>
	 */
	public static $allowed_blocks = array();

	/**
	 * @hook wp_abilities_api_init
	 */
	public function register_ability(): void {
		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Generate Links Newsletter', 'prc-email-builder' ),
				'description'         => __( 'Generates a weekly links newsletter from published content in the last 7–30 days (default 7).', 'prc-email-builder' ),
				'category'            => 'communication',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'context'         => array(
							'type'        => 'string',
							'description' => 'Optional extra instructions for generation.',
						),
						'researchTeamId'  => array(
							'type'        => 'number',
							'description' => 'Optional research-teams term ID to scope source content.',
						),
						'lookbackDays'    => array(
							'type'        => 'number',
							'minimum'     => self::MIN_LOOKBACK_DAYS,
							'maximum'     => self::MAX_LOOKBACK_DAYS,
							'description' => 'Number of days to look back for source content (default 7).',
						),
						'site_id'         => \PRC\Platform\AI\Utils\site_id_input_schema_property(),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'error'        => array( 'type' => 'string' ),
						'title'        => array( 'type' => 'string' ),
						'subject'      => array( 'type' => 'string' ),
						'previewText'  => array( 'type' => 'string' ),
						'content'      => array( 'type' => 'string' ),
						'sourcePostIds' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'number' ),
						),
					),
				),
				'execute_callback'    => function ( $input ) {
					return $this->with_site(
						$input,
						function () use ( $input ) {
							return $this->generate_links_newsletter( $input );
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
						'instructions' => $this->with_site_instructions( 'Generates a weekly links newsletter from published Pew Research Center content in the last 7–30 days (default 7). Optionally scope by researchTeamId and lookbackDays.' ),
						'readonly'     => false,
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
	public function generate_links_newsletter( $input ) {
		$context          = isset( $input['context'] ) ? sanitize_textarea_field( (string) $input['context'] ) : '';
		$research_team_id = isset( $input['researchTeamId'] ) ? absint( $input['researchTeamId'] ) : 0;
		$lookback_days    = isset( $input['lookbackDays'] ) ? absint( $input['lookbackDays'] ) : self::DEFAULT_LOOKBACK_DAYS;

		if ( $lookback_days < self::MIN_LOOKBACK_DAYS || $lookback_days > self::MAX_LOOKBACK_DAYS ) {
			return new WP_Error(
				'invalid_lookback_days',
				sprintf(
					/* translators: 1: minimum days, 2: maximum days */
					__( 'Lookback period must be between %1$d and %2$d days.', 'prc-email-builder' ),
					self::MIN_LOOKBACK_DAYS,
					self::MAX_LOOKBACK_DAYS
				)
			);
		}

		if ( $research_team_id > 0 && ! $this->is_valid_research_team( $research_team_id ) ) {
			return new WP_Error( 'invalid_research_team', __( 'The selected research team is invalid.', 'prc-email-builder' ) );
		}

		$source_posts = $this->get_recent_published_posts( $research_team_id, $lookback_days );
		if ( empty( $source_posts ) ) {
			return new WP_Error(
				'no_source_posts',
				sprintf(
					/* translators: %d: number of days */
					__( 'No published content was found in the last %d days for the selected scope.', 'prc-email-builder' ),
					$lookback_days
				)
			);
		}

		$system = $this->build_system_instruction( $context, $research_team_id );
		$prompt = $this->build_generation_prompt( $source_posts, $research_team_id, $lookback_days );

		$raw = $this->generate_json_via_ai_client( $prompt, $system );
		if ( '' === $raw ) {
			return new WP_Error( 'ai_generation_failed', __( 'AI generation failed.', 'prc-email-builder' ) );
		}

		$parsed = $this->parse_newsletter_response( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$parsed['sourcePostIds'] = array_values(
			array_map(
				static fn( array $post ) => (int) $post['id'],
				$source_posts
			)
		);

		return $parsed;
	}

	/**
	 * @return array<int, array{id: int, title: string, url: string, date: string, excerpt: string, researchTeams: array<int, string>}>
	 */
	private function get_recent_published_posts( int $research_team_id, int $lookback_days ): array {
		$query_args = array(
			'post_type'              => self::CONTENT_POST_TYPES,
			'post_status'            => 'publish',
			'posts_per_page'         => min( 120, max( 40, $lookback_days * 4 ) ),
			'post_parent'            => 0,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => true,
			'date_query'             => array(
				array(
					'after' => "{$lookback_days} days ago",
				),
			),
		);

		if ( $research_team_id > 0 ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => self::RESEARCH_TEAMS_TAXONOMY,
					'field'    => 'term_id',
					'terms'    => array( $research_team_id ),
				),
			);
		}

		$query = new WP_Query( $query_args );
		if ( ! $query->have_posts() ) {
			return array();
		}

		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$permalink = get_permalink( $post );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$excerpt = has_excerpt( $post ) ? (string) get_the_excerpt( $post ) : '';
			if ( '' === trim( $excerpt ) ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( (string) $post->post_content, true ), 35, '…' );
			}

			$team_names = array();
			if ( taxonomy_exists( self::RESEARCH_TEAMS_TAXONOMY ) ) {
				$teams = get_the_terms( $post, self::RESEARCH_TEAMS_TAXONOMY );
				if ( is_array( $teams ) ) {
					foreach ( $teams as $team ) {
						if ( $team instanceof \WP_Term ) {
							$team_names[] = $team->name;
						}
					}
				}
			}

			$posts[] = array(
				'id'            => (int) $post->ID,
				'title'         => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'           => $permalink,
				'date'          => get_the_date( 'Y-m-d', $post ),
				'excerpt'       => html_entity_decode( wp_strip_all_tags( $excerpt, true ), ENT_QUOTES, 'UTF-8' ),
				'researchTeams' => $team_names,
			);
		}

		return $posts;
	}

	private function is_valid_research_team( int $term_id ): bool {
		if ( ! taxonomy_exists( self::RESEARCH_TEAMS_TAXONOMY ) ) {
			return false;
		}

		$term = get_term( $term_id, self::RESEARCH_TEAMS_TAXONOMY );
		return $term instanceof \WP_Term && ! is_wp_error( $term );
	}

	private function build_system_instruction( string $extra_context, int $research_team_id ): string {
		$text = 'You are an email editor for Pew Research Center, a nonpartisan research organization. '
			. 'Create a concise weekly links newsletter that highlights recently published research. '
			. 'Use a neutral, professional tone. Only link to URLs provided in the source list — never invent links. '
			. 'Prefer the most notable or timely items when the source list is long. '
			. 'Return ONLY valid JSON with keys: title, subject, previewText, content. '
			. 'The content value must be WordPress block markup using only these blocks: '
			. 'core/post-date, core/heading, core/paragraph, core/list, core/list-item, core/separator, core/spacer. '
			. 'Each list item should include a linked headline and a short factual description. '
			. 'Start content with <!-- wp:post-date /--> then a level-2 heading for the newsletter title.';

		if ( $research_team_id > 0 ) {
			$term = get_term( $research_team_id, self::RESEARCH_TEAMS_TAXONOMY );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				$text .= "\n\nScope: Focus on content from the {$term->name} research team.";
			}
		}

		if ( '' !== $extra_context ) {
			$text .= "\n\nAdditional editor instructions:\n" . $extra_context;
		}

		$guidelines = $this->get_site_content_guidelines();
		if ( '' !== $guidelines ) {
			$text .= "\n\nSITE CONTENT GUIDELINES (authoritative):\n\n" . $guidelines;
		}

		return $text;
	}

	/**
	 * @param array<int, array{id: int, title: string, url: string, date: string, excerpt: string, researchTeams: array<int, string>}> $source_posts Source posts.
	 */
	private function build_generation_prompt( array $source_posts, int $research_team_id, int $lookback_days ): string {
		$week_label = wp_date( 'F j, Y' );
		$lines      = array(
			"Generate a weekly links newsletter for the week ending {$week_label}.",
			'',
			"Source posts (published in the last {$lookback_days} days):",
			wp_json_encode( $source_posts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'',
			'Requirements:',
			'- title: internal WordPress post title for the draft (include the week).',
			'- subject: email subject line (max 78 characters).',
			'- previewText: inbox preview / preheader text (max 140 characters).',
			'- content: Gutenberg block markup for the email body.',
		);

		if ( $research_team_id > 0 ) {
			$lines[] = '- Emphasize items relevant to the scoped research team.';
		}

		$lines[] = '';
		$lines[] = 'Return ONLY a JSON object. No markdown fences or extra prose.';

		return implode( "\n", $lines );
	}

	/**
	 * @return array{title: string, subject: string, previewText: string, content: string}|WP_Error
	 */
	private function parse_newsletter_response( string $raw ) {
		$raw = trim( $raw );
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/', '', (string) $raw );
		if ( preg_match( '/\{.*\}/s', (string) $raw, $matches ) ) {
			$raw = $matches[0];
		}

		$decoded = json_decode( (string) $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_ai_response', __( 'Could not parse the generated newsletter.', 'prc-email-builder' ) );
		}

		$title       = isset( $decoded['title'] ) ? sanitize_text_field( (string) $decoded['title'] ) : '';
		$subject     = isset( $decoded['subject'] ) ? sanitize_text_field( (string) $decoded['subject'] ) : '';
		$preview     = isset( $decoded['previewText'] ) ? sanitize_text_field( (string) $decoded['previewText'] ) : '';
		$content     = isset( $decoded['content'] ) ? (string) $decoded['content'] : '';
		$content     = $this->sanitize_block_content( $content );

		if ( '' === $title || '' === $subject || '' === $content ) {
			return new WP_Error( 'incomplete_ai_response', __( 'The generated newsletter was missing required fields.', 'prc-email-builder' ) );
		}

		if ( mb_strlen( $subject ) > 78 ) {
			$subject = mb_substr( $subject, 0, 78 );
		}

		if ( mb_strlen( $preview ) > 140 ) {
			$preview = mb_substr( $preview, 0, 140 );
		}

		return array(
			'title'       => $title,
			'subject'     => $subject,
			'previewText' => $preview,
			'content'     => $content,
		);
	}

	private function sanitize_block_content( string $content ): string {
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}

		// Preserve Gutenberg block comments; strip only executable markup.
		$content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $content );
		$content = preg_replace( '/<iframe\b[^>]*>.*?<\/iframe>/is', '', (string) $content );

		return (string) $content;
	}

	private function get_site_content_guidelines(): string {
		if ( ! function_exists( 'PRC\Platform\AI\Utils\get_content_guidelines_for_post' ) ) {
			return '';
		}

		$result = \PRC\Platform\AI\Utils\get_content_guidelines_for_post(
			0,
			array( 'task' => 'seo_metadata' )
		);
		if ( empty( $result['packet_text'] ) || ! is_string( $result['packet_text'] ) ) {
			return '';
		}

		return trim( $result['packet_text'] );
	}
}
