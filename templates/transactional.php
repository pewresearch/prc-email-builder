<?php
/**
 * Template Name: Transactional
 * Description:   Footer for Mandrill transactional emails (privacy, terms, no list-management links).
 *
 * Variables available when this file is included by newsletter-email-shell.php:
 *   string $content      Table-based HTML fragment from the content transformer.
 *   string $subject      Newsletter subject line.
 *   string $preview_text Preview / preheader text.
 */

use PRC\Platform\Email_Builder\Dark_Mode_Registry;
use PRC\Platform\Email_Builder\Email_Preset_Resolver;

$current_year = gmdate( 'Y' );
$logo_url     = content_url( 'images/logos/primary.svg' );
$org_url      = esc_url( home_url( '/' ) );
$privacy_url  = esc_url( home_url( '/about/privacy/' ) );
$terms_url    = esc_url( home_url( '/about/terms-and-conditions/' ) );

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

		<!-- ── Header ──────────────────────────────────────────────────── -->
		<tr>
			<td align="center" style="padding:24px 32px 20px;background-color:#ffffff;">
				<a href="<?php echo $org_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;border:0;">
					<img
						src="<?php echo esc_url( $logo_url ); ?>"
						alt="Pew Research Center"
						width="180"
						style="display:block;border:0;height:auto;"
					/>
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
			<td style="padding:0 32px;">
				<hr style="border:none;border-top:1px solid #e0e0e0;margin:0;" />
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:20px 32px 8px;font-size:12px;color:#999999;line-height:1.6;font-family:'franklin-gothic-urw',Verdana,Geneva,sans-serif;">
				<a href="<?php echo $privacy_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="footer-link" style="color:#999999;text-decoration:underline;">Privacy Policy</a>
				&nbsp;&middot;&nbsp;
				<a href="<?php echo $terms_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="footer-link" style="color:#999999;text-decoration:underline;">Terms of Use</a>
			</td>
		</tr>
		<tr>
			<td align="center" style="padding:0 32px 20px;font-size:12px;color:#999999;line-height:1.6;font-family:'franklin-gothic-urw',Verdana,Geneva,sans-serif;">
				<p style="margin:0 0 4px;">
					&copy; <?php echo esc_html( $current_year ); ?>&nbsp;
					<a href="<?php echo $org_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="footer-link" style="color:#999999;text-decoration:underline;">Pew Research Center</a>
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
