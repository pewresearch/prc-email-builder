<?php
declare(strict_types=1);
/**
 * Email template wrapper.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Wraps a raw content-transformer HTML fragment in the full newsletter
 * email document, producing a complete DOCTYPE page suitable for
 * Mailchimp delivery and iframe preview.
 *
 * The shell template (newsletter-email-shell.php) provides the DOCTYPE,
 * <head>, and <body> wrapper. It includes the body template at $body_template,
 * which is responsible for the outer card, logo header, content injection,
 * and footer rows.
 */
class Email_Template {

	/**
	 * Wraps an email content fragment in the appropriate newsletter HTML template.
	 *
	 * @param string $content  Table-based HTML fragment produced by the email provider.
	 * @param int    $post_id  Newsletter post ID — used to pull subject, preview text,
	 *                         and to resolve the active template.
	 * @return string Full DOCTYPE HTML email document.
	 */
	public static function wrap( string $content, int $post_id ): string {
		$subject      = get_post_meta( $post_id, 'prc_email_subject', true )
						?: get_the_title( $post_id );
		$preview_text = (string) get_post_meta( $post_id, 'prc_email_preview_text', true );

		$slug          = Template_Resolver::resolve( $post_id );
		$template      = $slug ? Template_Registry::get( $slug ) : Template_Registry::default();

		if ( ! $template ) {
			// Pathological: no body templates registered at all.
			return $content;
		}

		$body_template   = $template['path'];
		$view_online_url = Email_Merge_Tags::get_view_online_url( $post_id );
		$accent_color    = Newsletter_List::resolve_accent_color( $post_id );

		ob_start();
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		include PRC_EMAIL_BUILDER_DIR . '/templates/newsletter-email-shell.php';
		$html = (string) ob_get_clean();

		$html = Email_Merge_Tags::resolve( $html, $post_id );

		self::warn_if_large( $html, $post_id );

		return $html;
	}

	/**
	 * Emit a QM warning when the email HTML exceeds 90 KB.
	 * Gmail clips emails larger than 102 KB.
	 *
	 * @param string $html    Full HTML document.
	 * @param int    $post_id Newsletter post ID (for context in the warning).
	 */
	private static function warn_if_large( string $html, int $post_id ): void {
		$bytes = strlen( $html );
		if ( $bytes > 90000 ) {
			do_action(
				'qm/warn',
				sprintf(
					'Newsletter email HTML is %d bytes for post %d — Gmail will clip emails larger than 102 KB.',
					$bytes,
					$post_id
				)
			);
		}
	}
}
