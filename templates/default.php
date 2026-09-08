<?php
/**
 * Template Name: Default
 * Description:   Default Pew Research Center newsletter template.
 *
 * Variables available when this file is included by newsletter-email-shell.php:
 *   string $content         Table-based HTML fragment from the content transformer.
 *   string $subject         Newsletter subject line.
 *   string $preview_text    Preview / preheader text.
 *   string $view_online_url Website permalink for "View in browser" (campaign posts).
 *   string $accent_color    Optional hex accent from the campaign's newsletter list term.
 *
 * @package PRC\Platform\Email_Builder
 */

use PRC\Platform\Email_Builder\Dark_Mode_Registry;
use PRC\Platform\Email_Builder\Email_Preset_Resolver;

$current_year = gmdate( 'Y' );
$logo_url     = content_url( 'images/logos/primary.svg' );
$mso_logo_url = content_url( 'images/logo.png' );

$card_pair  = Email_Preset_Resolver::color_pair( 'ui-white' );
$card_light = '' !== $card_pair['light'] ? $card_pair['light'] : '#ffffff';
$card_class = Dark_Mode_Registry::register_color_pair( 'background-color', $card_light, $card_pair['dark'] );
$card_attr  = '' !== $card_class ? ' class="' . esc_attr( $card_class ) . '"' : '';
?>

<!-- Outer wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background-color:#f4f4f4;">
<tr>
<td align="center" style="padding:24px 0;">

	<!-- Inner 600px card -->
	<table width="600" cellpadding="0" cellspacing="0" border="0" role="presentation"<?php echo $card_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		style="background-color:<?php echo esc_attr( $card_light ); ?>;max-width:600px;width:100%;">

		<?php if ( ! empty( $accent_color ) ) : ?>
		<!-- ── Accent bar (newsletter list vertical color) ─────────────── -->
		<tr>
			<td style="background-color:<?php echo esc_attr( $accent_color ); ?>;height:6px;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</td>
		</tr>
		<?php endif; ?>

		<!-- ── Header ──────────────────────────────────────────────────── -->
		<tr>
			<td align="center" style="padding:24px 32px 20px;background-color:#ffffff;">
				<a href="https://www.pewresearch.org" target="_blank" rel="noopener noreferrer" style="display:inline-block;border:0;">
					<!--[if mso]>
					<img
						src="<?php echo esc_url( $mso_logo_url ); ?>"
						alt="Pew Research Center"
						width="180"
						height="27"
						style="display:block;border:0;width:180px;height:27px;"
					/>
					<![endif]-->
					<!--[if !mso]><!-->
					<img
						src="<?php echo esc_url( $logo_url ); ?>"
						alt="Pew Research Center"
						width="180"
						height="27"
						style="display:block;border:0;width:180px;height:27px;"
					/>
					<!--<![endif]-->
				</a>
			</td>
		</tr>

		<!-- ── Content ──────────────────────────────────────────────────── -->
		<tr>
			<td style="padding:0 32px;">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $content;
				?>
			</td>
		</tr>

		<!-- ── Footer ──────────────────────────────────────────────────── -->
		<tr>
			<td style="padding:0 32px;font-size:0;line-height:0;">
				<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">
					<tr>
						<td height="1" bgcolor="#e0e0e0" style="height:1px;line-height:1px;font-size:1px;background-color:#e0e0e0;border:0;mso-line-height-rule:exactly;">&nbsp;</td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:20px 32px 8px;font-size:12px;color:#999999;line-height:1.6;font-family:'franklin-gothic-urw',Verdana,Geneva,sans-serif;">
				<a href="<?php echo esc_url( $view_online_url ?? '' ); ?>" class="footer-link" style="color:#999999;text-decoration:underline;">View in browser</a>
				&nbsp;&middot;&nbsp;
				<a href="*|UPDATE_PROFILE|*" class="footer-link" style="color:#999999;text-decoration:underline;">Manage preferences</a>
				&nbsp;&middot;&nbsp;
				<a href="*|UNSUB|*" class="footer-link" style="color:#999999;text-decoration:underline;">Unsubscribe</a>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:0 32px 20px;font-size:12px;color:#999999;line-height:1.6;font-family:'franklin-gothic-urw',Verdana,Geneva,sans-serif;">
				<p style="margin:0 0 4px;">
					&copy; <?php echo esc_html( $current_year ); ?>&nbsp;
					<a href="https://www.pewresearch.org" class="footer-link" style="color:#999999;text-decoration:underline;">Pew Research Center</a>
				</p>
				<p style="margin:0;">901 E St NW, Washington, DC 20004</p>
			</td>
		</tr>

	</table>
	<!-- /Inner card -->

</td>
</tr>
</table>
<!-- /Outer wrapper -->
