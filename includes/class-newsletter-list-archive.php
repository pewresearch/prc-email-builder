<?php
declare(strict_types=1);
/**
 * Newsletter list archive context for Mailchimp forms.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Stamps the queried newsletter list's Mailchimp audience/segment onto
 * prc-block/form subscribe actions rendered on list archives.
 */
class Newsletter_List_Archive {

	const MAILCHIMP_SUBSCRIBE_ACTION = 'subscribe';

	public function __construct( Loader $loader ) {
		$loader->add_filter( 'render_block_prc-block/form', $this, 'inject_mailchimp_targeting', 10, 2 );
	}

	/**
	 * Merge archive targeting into a form interactivity context.
	 *
	 * @param array<string, mixed>                    $context   Decoded data-wp-context.
	 * @param array{audience_id: string, segment_id: string} $targeting Archive targeting.
	 * @return array<string, mixed>
	 */
	public static function merge_mailchimp_action_config( array $context, array $targeting ): array {
		$action_config = array();
		if ( isset( $context['actionConfig'] ) && is_array( $context['actionConfig'] ) ) {
			$action_config = $context['actionConfig'];
		}

		// Archive targeting replaces both IDs so leftover template values do not mix.
		if ( '' !== $targeting['audience_id'] ) {
			$action_config['audienceId'] = $targeting['audience_id'];
		} else {
			unset( $action_config['audienceId'] );
		}
		if ( '' !== $targeting['segment_id'] ) {
			$action_config['segmentId'] = $targeting['segment_id'];
		} else {
			unset( $action_config['segmentId'] );
		}

		$context['actionConfig'] = $action_config;
		return $context;
	}

	/**
	 * Write merged targeting into the form's data-wp-context attribute.
	 *
	 * @param string                                  $html      Rendered form HTML.
	 * @param array{audience_id: string, segment_id: string} $targeting Archive targeting.
	 */
	public static function stamp_form_context( string $html, array $targeting ): string {
		if ( ! class_exists( \WP_HTML_Tag_Processor::class ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( 'form' ) ) {
			return $html;
		}

		$raw = $processor->get_attribute( 'data-wp-context' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $html;
		}

		$context = json_decode( $raw, true );
		if ( ! is_array( $context ) ) {
			return $html;
		}

		$encoded = wp_json_encode( self::merge_mailchimp_action_config( $context, $targeting ) );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $html;
		}

		$processor->set_attribute( 'data-wp-context', $encoded );
		return $processor->get_updated_html();
	}

	/**
	 * On newsletter list archives, stamp Mailchimp IDs onto subscribe forms.
	 *
	 * @hook render_block_prc-block/form
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function inject_mailchimp_targeting( string $block_content, array $block ): string {
		$action = $block['attrs']['action'] ?? '';
		if ( self::MAILCHIMP_SUBSCRIBE_ACTION !== $action ) {
			return $block_content;
		}

		$targeting = Newsletter_List::resolve_queried_archive_targeting();
		if ( null === $targeting ) {
			return $block_content;
		}

		return self::stamp_form_context( $block_content, $targeting );
	}
}
