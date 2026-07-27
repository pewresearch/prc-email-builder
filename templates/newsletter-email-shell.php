<?php
/**
 * Newsletter HTML email shell.
 *
 * Variables set by Email_Template::wrap() before include:
 *   string $content         Table-based HTML fragment.
 *   string $subject         Newsletter subject.
 *   string $preview_text    Preheader text.
 *   string $body_template   Path to body template PHP file.
 *   string $view_online_url Website permalink for "View in browser" (campaign posts).
 *   string $accent_color    Optional hex accent from the campaign's newsletter list term.
 */
use PRC\Platform\Email_Builder\Dark_Mode_Registry;
use PRC\Platform\Email_Builder\Email_Preset_Resolver;

// Card background dark-mode pair (body template registers the class on the cell).
$white_pair = Email_Preset_Resolver::color_pair( 'ui-white' );
if ( '' !== $white_pair['light'] && '' !== $white_pair['dark'] && $white_pair['light'] !== $white_pair['dark'] ) {
	Dark_Mode_Registry::register_color_pair( 'background-color', $white_pair['light'], $white_pair['dark'] );
}
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
<link rel="stylesheet" href="https://use.typekit.net/tic0xoy.css">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<meta name="format-detection" content="telephone=no, address=no, email=no, date=no">
<!--[if mso]>
<noscript>
  <xml>
    <o:OfficeDocumentSettings>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
  </xml>
</noscript>
<![endif]-->
<style type="text/css">
  body { margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
  img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
  a { text-decoration: none; }
  /* Stop iOS Mail / Gmail from re-coloring auto-detected links */
  a[x-apple-data-detectors],
  .x-gmail-data-detectors,
  .x-gmail-data-detectors *,
  .aBn { color: inherit !important; text-decoration: inherit !important; }
  /* Footer links — keep gray on all clients */
  .footer-link, .footer-link:link, .footer-link:visited { color: #999999 !important; text-decoration: underline !important; }
  /* Body content links — blue */
  .body-link, .body-link:link, .body-link:visited { color: #2b6dad !important; text-decoration: underline !important; }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f4;font-family:Georgia,'Times New Roman',serif;color-scheme:light dark;">

<?php if ( ! empty( $preview_text ) ) : ?>
<!--[if !mso]><!-->
<div style="display:none;font-size:1px;color:#f4f4f4;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
	<?php echo esc_html( $preview_text ); ?>
	&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
</div>
<!--<![endif]-->
<?php endif; ?>

<?php
// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
include $body_template;

$dark_mode_css = Dark_Mode_Registry::get_css();
if ( '' !== $dark_mode_css ) :
	?>
<style type="text/css">
@media (prefers-color-scheme: dark) {
<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated from trusted preset registry.
	echo $dark_mode_css;
?>
}
</style>
<?php endif; ?>

</body>
</html>
