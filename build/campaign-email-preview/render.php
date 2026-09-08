<?php
/**
 * Server render for the campaign email preview block.
 *
 * @package PRC\Platform\Email_Builder
 *
 * Variables exposed by WordPress:
 *   array    $attributes Block attributes.
 *   string   $content    Block default content (unused).
 *   WP_Block $block      Block instance with context.
 */

use PRC\Platform\Email_Builder\Public_Email_Preview;
use PRC\Platform\Email_Builder\REST_API;

$campaign_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
if ( $campaign_post_id <= 0 || true !== Public_Email_Preview::validate_previewable( $campaign_post_id ) ) {
	return;
}

$frame_width  = isset( $attributes['frameWidth'] ) ? (int) $attributes['frameWidth'] : 375;
$frame_height = isset( $attributes['frameHeight'] ) ? (int) $attributes['frameHeight'] : 560;
$show_fade    = ! isset( $attributes['showFade'] ) || (bool) $attributes['showFade'];
$show_link    = ! isset( $attributes['showLink'] ) || (bool) $attributes['showLink'];
$link_text    = isset( $attributes['linkText'] ) && is_string( $attributes['linkText'] )
	? $attributes['linkText']
	: __( 'Read the latest issue', 'prc-email-builder' );

$frame_width  = max( 240, min( 480, $frame_width ) );
$frame_height = max( 320, min( 900, $frame_height ) );

$preview_url = rest_url( REST_API::NAMESPACE . '/campaign-preview/' . $campaign_post_id );
$permalink   = get_permalink( $campaign_post_id );
$link_url    = $permalink;
if (
	isset( $attributes['linkUrl'] )
	&& is_string( $attributes['linkUrl'] )
	&& '' !== trim( $attributes['linkUrl'] )
) {
	$custom_link_url = esc_url( $attributes['linkUrl'] );
	if ( '' !== $custom_link_url ) {
		$link_url = $custom_link_url;
	}
}
$campaign_title = get_the_title( $campaign_post_id );
$iframe_title   = sprintf(
	/* translators: %s: campaign title */
	__( 'Email preview: %s', 'prc-email-builder' ),
	$campaign_title ? $campaign_title : __( 'Newsletter', 'prc-email-builder' )
);

$classes = array( 'prc-email-builder-campaign-email-preview' );
if ( $show_fade ) {
	$classes[] = 'has-fade';
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class' => implode( ' ', $classes ),
		'style' => sprintf(
			'--preview-width:%d;--preview-height:%d;',
			$frame_width,
			$frame_height
		),
	)
);
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() ?>>
	<div class="prc-email-builder-campaign-email-preview__frame">
		<iframe
			class="prc-email-builder-campaign-email-preview__iframe"
			src="<?php echo esc_url( $preview_url ); ?>"
			title="<?php echo esc_attr( $iframe_title ); ?>"
			loading="lazy"
			sandbox="allow-popups allow-popups-to-escape-sandbox"
		></iframe>
		<?php if ( $show_fade ) : ?>
			<div class="prc-email-builder-campaign-email-preview__fade" aria-hidden="true"></div>
		<?php endif; ?>
	</div>
	<?php if ( $show_link && is_string( $link_url ) && '' !== $link_url ) : ?>
		<p class="prc-email-builder-campaign-email-preview__link">
			<a
				href="<?php echo esc_url( $link_url ); ?>"
				target="_blank"
				rel="noopener noreferrer"
			>
				<?php echo esc_html( $link_text ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
