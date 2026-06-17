<?php
declare(strict_types=1);
/**
 * Unit tests for the deterministic email-HTML pipeline.
 *
 * Tests:
 *  - Email_Block_Registry: register / get / has / reset
 *  - Email_Style_Resolver: typography_inline_css, font resolution
 *  - Email_Block_Integration callbacks (paragraph, heading, spacer, separator,
 *    list, image, quote, button, fallback)
 *  - Email_Block_Converter: block dispatching, container recursion, freeform
 *  - Guards: no {{PRC_EMAIL_*}} tokens, no <!DOCTYPE / <html> leaks
 *
 * Run with:
 *   php plugins/prc-email-builder/tests/test-email-block-converter.php
 */

// ---------------------------------------------------------------------------
// WordPress stubs (only what the tested classes actually call)
// ---------------------------------------------------------------------------

namespace {
	if ( ! class_exists( 'WP_Post' ) ) {
		class WP_Post {
			public int    $ID         = 1;
			public string $post_type  = 'prc_email_campaign';
			public string $post_status = 'publish';
			public string $post_content = '';
			public function __construct( array $data = array() ) {
				foreach ( $data as $k => $v ) {
					$this->$k = $v;
				}
			}
		}
	}

	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		class WP_Block_Type_Registry {
			private static ?WP_Block_Type_Registry $instance = null;
			public static function get_instance(): WP_Block_Type_Registry {
				if ( null === self::$instance ) {
					self::$instance = new self();
				}
				return self::$instance;
			}
			public function get_registered( string $name ): ?object {
				return null;
			}
		}
	}

	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		/**
		 * Minimal tag processor for unit tests (IMG + A tags).
		 */
		class WP_HTML_Tag_Processor {
			private string $html;
			private string $updated;
			/** @var list<array{tag:string,attrs:array<string,string>}> */
			private array $tags = array();
			private int $index = -1;

			public function __construct( string $html ) {
				$this->html    = $html;
				$this->updated = $html;
				if ( preg_match_all( '#<(img|a)\b([^>]*)>#i', $html, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $m ) {
						$attrs = array();
						if ( preg_match_all( '#(\w[\w-]*)\s*=\s*(["\'])(.*?)\2#i', $m[2], $am, PREG_SET_ORDER ) ) {
							foreach ( $am as $a ) {
								$attrs[ strtolower( $a[1] ) ] = $a[3];
							}
						}
						$this->tags[] = array(
							'tag'   => strtoupper( $m[1] ),
							'attrs' => $attrs,
						);
					}
				}
			}

			public function next_tag( string $tag = '' ): bool {
				$want = '' !== $tag ? strtoupper( $tag ) : '';
				do {
					$this->index++;
					if ( ! isset( $this->tags[ $this->index ] ) ) {
						return false;
					}
				} while ( '' !== $want && $this->tags[ $this->index ]['tag'] !== $want );
				return true;
			}

			public function get_attribute( string $attr ): ?string {
				if ( $this->index < 0 || ! isset( $this->tags[ $this->index ] ) ) {
					return null;
				}
				$key = strtolower( $attr );
				return $this->tags[ $this->index ]['attrs'][ $key ] ?? null;
			}

			public function set_attribute( string $attr, string $value ): void {
				if ( $this->index < 0 || ! isset( $this->tags[ $this->index ] ) ) {
					return;
				}
				$this->tags[ $this->index ]['attrs'][ strtolower( $attr ) ] = $value;
				$this->rebuild_html();
			}

			private function rebuild_html(): void {
				$out = $this->html;
				if ( ! preg_match_all( '#<(img|a)\b([^>]*)>#i', $this->html, $matches, PREG_OFFSET_CAPTURE ) ) {
					$this->updated = $out;
					return;
				}
				$offset = 0;
				$count  = count( $matches[0] );
				for ( $i = 0; $i < $count; $i++ ) {
					if ( ! isset( $this->tags[ $i ] ) ) {
						break;
					}
					$tag_name = $matches[1][ $i ][0];
					$full     = $matches[0][ $i ][0];
					$pos      = $matches[0][ $i ][1];
					$attrs    = $this->tags[ $i ]['attrs'];
					$parts    = array();
					foreach ( $attrs as $k => $v ) {
						$parts[] = $k . '="' . htmlspecialchars( $v, ENT_QUOTES ) . '"';
					}
					$replacement = '<' . $tag_name . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . '>';
					$out         = substr( $out, 0, $pos + $offset ) . $replacement . substr( $out, $pos + $offset + strlen( $full ) );
					$offset     += strlen( $replacement ) - strlen( $full );
				}
				$this->updated = $out;
			}

			public function get_updated_html(): string {
				return $this->updated;
			}
		}
	}

	function wp_get_attachment_url( int $id ): string {
		return '';
	}

	function esc_attr( string $v ): string { return htmlspecialchars( $v, ENT_QUOTES ); }
	function esc_html( string $v ): string { return htmlspecialchars( $v, ENT_QUOTES ); }
	function esc_url( string $v ): string  { return filter_var( $v, FILTER_SANITIZE_URL ) ?: ''; }
	function sanitize_key( string $v ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $v ) ); }
	function wp_strip_all_tags( string $v ): string { return strip_tags( $v ); }
	function apply_filters( string $hook, $value, ...$args ) { return $value; }
	function do_action( string $hook, ...$args ): void {}
	function render_block( array $block ): string { return $block['innerHTML'] ?? ''; }
	function get_post( $id ): ?WP_Post {
		if ( $id instanceof WP_Post ) return $id;
		return null;
	}
	function parse_blocks( string $content ): array { return array(); }
	function setup_postdata( $post ): void {}
	function wp_reset_postdata(): void {}
	function get_post_thumbnail_id( int $post_id ): int { return 0; }
	function wp_get_attachment_image_src( int $id, string $size ): ?array { return null; }
	function get_post_thumbnail( int $post_id ): string { return ''; }
	function get_option( string $name ) { return 'date_format' === $name ? 'F j, Y' : ''; }
	function get_the_date( string $format = '', $post = null ): string { return 'June 4, 2026'; }
	function get_the_modified_date( string $format = '', $post = null ): string { return 'June 5, 2026'; }
	function get_permalink( $post = null ): string { return 'https://example.com/post'; }

	function sanitize_html_class( string $class ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $class ) ) ?? '';
	}

	/**
	 * @param array<int|string,mixed> $path
	 * @param array<string,mixed>     $settings
	 * @return mixed
	 */
	function wp_get_global_settings( array $path = array(), $settings = array() ) {
		// Mirror real wp_get_global_settings(): palette + spacingSizes are
		// keyed by origin (default/theme/custom), NOT flat lists.
		$all = array(
			'color' => array(
				'palette' => array(
					'default' => array(
						array(
							'slug'  => 'white',
							'color' => '#ffffff',
							'name'  => 'White',
						),
					),
					'theme' => array(
						array(
							'slug'  => 'ui-gray-very-light',
							'color' => 'light-dark(#F7F7F7, #242424)',
							'name'  => 'UI Gray Very Light',
						),
						array(
							'slug'  => 'ui-beige-light',
							'color' => 'light-dark(#f0f0e6, #333028)',
							'name'  => 'UI Beige Light',
						),
						array(
							'slug'  => 'ui-white',
							'color' => 'light-dark(#ffffff, #1a1a1a)',
							'name'  => 'UI White',
						),
						array(
							'slug'  => 'ui-black',
							'color' => 'light-dark(#101010, #f0f0f0)',
							'name'  => 'UI Black',
						),
					),
				),
			),
			'spacing' => array(
				'spacingSizes' => array(
					'theme' => array(
						array( 'slug' => '40', 'size' => '2rem' ),
					),
				),
			),
		);
		if ( empty( $path ) ) {
			return $all;
		}
		$node = $all;
		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		return $node;
	}

	/**
	 * @param array<string,mixed> $style
	 * @param array<string,mixed> $options
	 * @return array{css:string,declarations:array<string,string>,classnames:string}
	 */
	function wp_style_engine_get_styles( array $style, array $options = array() ): array {
		$declarations = array();
		if ( isset( $style['color']['text'] ) && is_string( $style['color']['text'] ) ) {
			$declarations['color'] = $style['color']['text'];
		}
		if ( isset( $style['color']['background'] ) && is_string( $style['color']['background'] ) ) {
			$declarations['background-color'] = $style['color']['background'];
		}
		if ( isset( $style['spacing']['padding'] ) && is_array( $style['spacing']['padding'] ) ) {
			foreach ( $style['spacing']['padding'] as $side => $val ) {
				if ( is_string( $val ) ) {
					$declarations[ 'padding-' . $side ] = $val;
				}
			}
		}
		$css = '';
		foreach ( $declarations as $prop => $val ) {
			$css .= $prop . ':' . $val . ';';
		}
		return array(
			'css'          => $css,
			'declarations' => $declarations,
			'classnames'   => '',
		);
	}
}

// ---------------------------------------------------------------------------
// Load tested classes (no WordPress autoloader in tests)
// ---------------------------------------------------------------------------

namespace PRC\Platform\Email_Builder {
	require_once __DIR__ . '/../includes/email/class-email-block-registry.php';
	require_once __DIR__ . '/../includes/email/class-email-block-resolver.php';
	require_once __DIR__ . '/../includes/email/class-dark-mode-registry.php';
	require_once __DIR__ . '/../includes/email/class-email-preset-resolver.php';
	require_once __DIR__ . '/../includes/email/class-email-style-resolver.php';
	require_once __DIR__ . '/../includes/email/class-html-to-email-converter.php';
	require_once __DIR__ . '/../includes/email/class-email-block-integration.php';
	require_once __DIR__ . '/../includes/email/class-email-block-converter.php';
}

// ---------------------------------------------------------------------------
// Test runner helpers
// ---------------------------------------------------------------------------

namespace Tests\Newsletter\Email {

	use PRC\Platform\Email_Builder\Dark_Mode_Registry;
	use PRC\Platform\Email_Builder\Email_Block_Registry;
	use PRC\Platform\Email_Builder\Email_Preset_Resolver;
	use PRC\Platform\Email_Builder\Email_Style_Resolver;
	use PRC\Platform\Email_Builder\Email_Block_Integration;
	use PRC\Platform\Email_Builder\Email_Block_Converter;
	use PRC\Platform\Email_Builder\Html_To_Email_Converter;

	$passed = 0;
	$failed = 0;

	function assert_true( bool $cond, string $label ): void {
		global $passed, $failed;
		if ( $cond ) {
			echo "  PASS  $label\n";
			$passed++;
		} else {
			echo "  FAIL  $label\n";
			$failed++;
		}
	}

	function assert_contains( string $needle, string $haystack, string $label ): void {
		assert_true( str_contains( $haystack, $needle ), "$label [contains: $needle]" );
	}

	function assert_not_contains( string $needle, string $haystack, string $label ): void {
		assert_true( ! str_contains( $haystack, $needle ), "$label [not contains: $needle]" );
	}

	function make_post( string $content = '' ): \WP_Post {
		return new \WP_Post( array( 'post_content' => $content ) );
	}

	// -----------------------------------------------------------------------
	// Suite: Email_Block_Registry
	// -----------------------------------------------------------------------

	echo "\n=== Email_Block_Registry ===\n";

	Email_Block_Registry::reset();

	assert_true( ! Email_Block_Registry::has( 'core/paragraph' ), 'empty registry has no paragraph' );

	Email_Block_Registry::register( 'core/paragraph', fn( $b, $p ) => '<p>test</p>' );
	assert_true( Email_Block_Registry::has( 'core/paragraph' ), 'has after register' );

	$cb = Email_Block_Registry::get( 'core/paragraph' );
	assert_true( is_callable( $cb ), 'get returns callable' );

	$result = call_user_func( $cb, array(), new \WP_Post() );
	assert_true( '<p>test</p>' === $result, 'callback returns expected value' );

	assert_true( null === Email_Block_Registry::get( 'core/image' ), 'get unknown returns null' );

	Email_Block_Registry::reset();
	assert_true( ! Email_Block_Registry::has( 'core/paragraph' ), 'reset clears registry' );

	// -----------------------------------------------------------------------
	// Suite: Email_Style_Resolver
	// -----------------------------------------------------------------------

	echo "\n=== Email_Style_Resolver ===\n";

	$css = Email_Style_Resolver::typography_inline_css( array() );
	assert_true( '' === $css, 'empty attrs produces empty css' );

	$css = Email_Style_Resolver::typography_inline_css( array(
		'style' => array(
			'typography' => array(
				'fontFamily'  => 'serif',
				'fontSize'    => '18px',
				'lineHeight'  => '26px',
			),
		),
	) );
	assert_contains( 'font-family:', $css, 'has font-family' );
	assert_contains( 'font-size:18px', $css, 'has font-size 18px' );
	assert_contains( 'line-height:26px', $css, 'has line-height' );
	assert_contains( Email_Style_Resolver::EMAIL_FONT_SERIF, $css, 'serif resolves to stack' );

	assert_true( Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS === Email_Style_Resolver::resolve_font_family( 'sans-serif' ), 'sans-serif maps correctly' );
	assert_true( Email_Style_Resolver::EMAIL_FONT_FRANKLIN_SANS === Email_Style_Resolver::resolve_font_family( 'franklin-gothic' ), 'franklin maps correctly' );
	assert_true( '' === Email_Style_Resolver::resolve_font_family( 'var(--wp--preset--font-family--body)' ), 'var() resolves to empty' );

	assert_true( '16px' === Email_Style_Resolver::resolve_font_size( 'medium' ), 'medium preset maps to 16px' );
	assert_true( '28px' === Email_Style_Resolver::resolve_font_size( 'h-one' ), 'h-one preset maps to 28px' );
	assert_true( '22px' === Email_Style_Resolver::resolve_font_size( 'var:preset|font-size|h-two' ), 'var:preset maps h-two' );
	assert_true( '14px' === Email_Style_Resolver::resolve_font_size( '14px' ), 'concrete px passes through' );
	assert_true( '' === Email_Style_Resolver::resolve_font_size( 'unknown-slug' ), 'unknown slug returns empty' );

	// -----------------------------------------------------------------------
	// Suite: Email_Block_Integration callbacks
	// -----------------------------------------------------------------------

	echo "\n=== Email_Block_Integration callbacks ===\n";

	$post       = make_post();
	$integration = new Email_Block_Integration( new class { public function add_action( ...$a ): void {} } );

	// Register all core callbacks into a clean registry.
	Email_Block_Registry::reset();
	Dark_Mode_Registry::reset();
	$integration->register_block_callbacks();

	echo "\n=== Email_Preset_Resolver ===\n";

	$pair = Email_Preset_Resolver::color_pair( 'ui-gray-very-light' );
	assert_true( '#F7F7F7' === $pair['light'], 'color_pair: light branch' );
	assert_true( '#242424' === $pair['dark'], 'color_pair: dark branch' );
	assert_true( '32px' === Email_Preset_Resolver::spacing_value( 'var:preset|spacing|40' ), 'spacing_value: preset 40' );

	echo "\n=== Dark_Mode_Registry ===\n";

	Dark_Mode_Registry::reset();
	$cls1 = Dark_Mode_Registry::register_color_pair( 'background-color', '#F7F7F7', '#242424' );
	$cls2 = Dark_Mode_Registry::register_color_pair( 'background-color', '#F7F7F7', '#242424' );
	assert_true( $cls1 === $cls2, 'dark registry: dedupe same pair' );
	assert_contains( '#242424', Dark_Mode_Registry::get_css(), 'dark registry: contains dark hex' );
	assert_contains( '!important', Dark_Mode_Registry::get_css(), 'dark registry: uses important' );
	Dark_Mode_Registry::reset();
	assert_true( '' === Dark_Mode_Registry::get_css(), 'dark registry: reset clears' );

	// Paragraph.
	$p_block = array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array(),
		'innerHTML'    => '<p>Hello <a href="https://example.com">world</a>.</p>',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/paragraph' ), $p_block, $post );
	assert_contains( '<p style=', $html, 'paragraph: has styled <p>' );
	assert_contains( 'Hello', $html, 'paragraph: preserves text' );
	assert_not_contains( '<table width="100%"', $html, 'paragraph: not wrapped in 100% table' );
	assert_not_contains( 'padding:0 32px', $html, 'paragraph: no per-block horizontal padding' );
	assert_not_contains( '{{PRC_EMAIL_', $html, 'paragraph: no legacy tokens' );
	assert_not_contains( '<!DOCTYPE', $html, 'paragraph: no DOCTYPE' );
	assert_contains( 'body-link', $html, 'paragraph: body-link class on anchor' );
	assert_contains( 'color:#2b6dad', $html, 'paragraph: inline blue link colour' );
	assert_contains( 'world', $html, 'paragraph: link text preserved' );

	// Heading level 1.
	$h1_block = array(
		'blockName'    => 'core/heading',
		'attrs'        => array( 'level' => 1 ),
		'innerHTML'    => '<h1>Big Title</h1>',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/heading' ), $h1_block, $post );
	assert_contains( '<h1', $html, 'heading: uses <h1> tag' );
	assert_contains( 'font-size:28px', $html, 'heading h1: correct font-size' );
	assert_contains( 'Big Title', $html, 'heading: preserves text' );
	assert_not_contains( '<table width="100%"', $html, 'heading: not wrapped in 100% table' );

	// Heading level 2 (default).
	$h2_block = array(
		'blockName' => 'core/heading',
		'attrs'     => array(),
		'innerHTML' => '<h2>Section</h2>',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/heading' ), $h2_block, $post );
	assert_contains( '<h2', $html, 'heading: default is h2' );
	assert_contains( 'font-size:22px', $html, 'heading h2: correct font-size' );

	// Spacer.
	$spacer_block = array(
		'blockName'   => 'core/spacer',
		'attrs'       => array( 'height' => '30px' ),
		'innerHTML'   => '',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/spacer' ), $spacer_block, $post );
	assert_contains( 'height="30"', $html, 'spacer: correct height attribute' );
	assert_contains( 'height:30px', $html, 'spacer: correct inline height' );

	// Spacer numeric height (no units).
	$spacer_num = array_merge( $spacer_block, array( 'attrs' => array( 'height' => 25 ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/spacer' ), $spacer_num, $post );
	assert_contains( 'height="25"', $html, 'spacer: numeric height' );

	// Separator.
	$sep_block = array( 'blockName' => 'core/separator', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array() );
	$html = call_user_func( Email_Block_Registry::get( 'core/separator' ), $sep_block, $post );
	assert_contains( '<hr', $html, 'separator: has <hr>' );
	assert_not_contains( '{{PRC_EMAIL_', $html, 'separator: no tokens' );

	// Image — default alignment.
	$img_block = array(
		'blockName'   => 'core/image',
		'attrs'       => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Test image' ),
		'innerHTML'   => '',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_block, $post );
	assert_contains( 'width="536"', $html, 'image default: content inner width 536' );
	assert_contains( 'https://example.com/img.jpg', $html, 'image: URL preserved' );
	assert_contains( 'Test image', $html, 'image: alt preserved' );

	// Image — left alignment (bare float, clamped width).
	$img_left = array_merge( $img_block, array( 'attrs' => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Left', 'align' => 'left', 'width' => '368px' ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_left, $post );
	assert_contains( 'align="left"', $html, 'image left: uses align attribute' );
	assert_contains( 'width="300"', $html, 'image left: clamped to 300px' );
	assert_contains( 'max-width:300px', $html, 'image left: max-width matches clamp' );
	assert_not_contains( '<table align="left"', $html, 'image left: bare img not float table' );

	// Image — right alignment.
	$img_right = array_merge( $img_block, array( 'attrs' => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Right', 'align' => 'right', 'width' => '368px' ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_right, $post );
	assert_contains( 'align="right"', $html, 'image right: uses align attribute' );
	assert_contains( 'width="300"', $html, 'image right: clamped width' );

	// Image — center alignment.
	$img_center = array_merge( $img_block, array( 'attrs' => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Center', 'align' => 'center' ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_center, $post );
	assert_contains( 'align="center"', $html, 'image center: td align' );
	assert_contains( 'width="600"', $html, 'image center: undimensioned width defaults to 600px' );
	assert_contains( 'max-width:600px', $html, 'image center: max-width 600px' );
	assert_contains( 'width:100%', $html, 'image center: responsive width 100%' );

	// Image — center alignment clamps wide declared width.
	$img_center_wide = array_merge( $img_block, array( 'attrs' => array( 'url' => 'https://example.com/img.jpg', 'alt' => 'Center wide', 'align' => 'center', 'width' => 800 ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_center_wide, $post );
	assert_contains( 'width="600"', $html, 'image center: clamps declared width to 600' );
	assert_contains( 'max-width:600px', $html, 'image center wide: max-width 600px' );

	// Image — innerHTML src/width + class-based alignright (editor fidelity).
	$img_inner = array(
		'blockName'   => 'core/image',
		'attrs'       => array(),
		'innerHTML'   => '<figure class="wp-block-image alignright"><img src="https://cdn.example/chart.png?w=640" alt="Chart" style="width:368px" width="368" /></figure>',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_inner, $post );
	assert_contains( 'https://cdn.example/chart.png?w=640', $html, 'image innerHTML: preserves resized src URL' );
	assert_contains( 'align="right"', $html, 'image innerHTML: alignright class' );
	assert_contains( 'width="300"', $html, 'image innerHTML: width clamped' );

	// Float wrap: paragraph after image is bare <p>, not 100% table.
	$wrap_html  = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_right, $post );
	$wrap_html .= call_user_func( Email_Block_Registry::get( 'core/paragraph' ), $p_block, $post );
	assert_contains( '<img', $wrap_html, 'wrap: has float img' );
	assert_contains( '<p style=', $wrap_html, 'wrap: following paragraph is bare' );
	assert_not_contains( '<table width="100%"', $wrap_html, 'wrap: no 100% table between float and text' );

	// Empty image block returns empty.
	$img_empty = array_merge( $img_block, array( 'attrs' => array() ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/image' ), $img_empty, $post );
	assert_true( '' === $html, 'image: no url returns empty string' );

	// List block.
	$list_block = array(
		'blockName'   => 'core/list',
		'attrs'       => array( 'ordered' => false ),
		'innerHTML'   => '',
		'innerContent' => array(),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'core/list-item',
				'attrs'       => array(),
				'innerHTML'   => '<li>First item</li>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
			array(
				'blockName'   => 'core/list-item',
				'attrs'       => array(),
				'innerHTML'   => '<li>Second item</li>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/list' ), $list_block, $post );
	assert_contains( '<ul', $html, 'list: uses <ul>' );
	assert_contains( '<li', $html, 'list: uses <li>' );
	assert_contains( 'First item', $html, 'list: has first item' );
	assert_contains( 'Second item', $html, 'list: has second item' );
	assert_not_contains( '<table width="100%"', $html, 'list: not wrapped in 100% table' );

	// Ordered list.
	$ordered_list = array_merge( $list_block, array( 'attrs' => array( 'ordered' => true ) ) );
	$html = call_user_func( Email_Block_Registry::get( 'core/list' ), $ordered_list, $post );
	assert_contains( '<ol', $html, 'ordered list: uses <ol>' );
	assert_contains( 'First item', $html, 'ordered list: first item' );
	assert_contains( 'Second item', $html, 'ordered list: second item' );

	// Quote.
	$quote_block = array(
		'blockName'   => 'core/quote',
		'attrs'       => array(),
		'innerHTML'   => '<blockquote>Quoted text here.</blockquote>',
		'innerContent' => array(),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerHTML'   => '<p>Quoted text here.</p>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/quote' ), $quote_block, $post );
	assert_contains( 'border-left', $html, 'quote: has border-left' );
	assert_contains( 'font-style:italic', $html, 'quote: is italic' );
	assert_contains( 'Quoted text here.', $html, 'quote: text preserved' );

	// Group with background + padding.
	Dark_Mode_Registry::reset();
	$group_block = array(
		'blockName'   => 'core/group',
		'attrs'       => array(
			'backgroundColor' => 'ui-gray-very-light',
			'style'           => array(
				'spacing' => array(
					'padding' => array(
						'top'    => 'var:preset|spacing|40',
						'right'  => 'var:preset|spacing|40',
						'bottom' => 'var:preset|spacing|40',
						'left'   => 'var:preset|spacing|40',
					),
				),
			),
			'layout'          => array( 'type' => 'constrained' ),
		),
		'innerHTML'    => '',
		'innerContent' => array(),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerHTML'   => '<p>Inside group</p>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/group' ), $group_block, $post );
	assert_contains( 'background-color:#F7F7F7', $html, 'group: light background inline' );
	assert_contains( 'padding:32px', $html, 'group: resolved padding' );
	assert_contains( 'Inside group', $html, 'group: inner paragraph' );
	assert_contains( 'dm-', $html, 'group: dark mode class on cell' );
	assert_not_contains( 'var(--wp--preset--', $html, 'group: no css vars leaked' );

	// Group with a named backgroundColor from the theme `theme` origin bucket
	// (regression: origin-keyed palette must still resolve to a literal).
	Dark_Mode_Registry::reset();
	$beige_group = array(
		'blockName'   => 'core/group',
		'attrs'       => array(
			'backgroundColor' => 'ui-beige-light',
			'textColor'       => 'ui-black',
			'style'           => array(
				'elements' => array(
					'link' => array( 'color' => array( 'text' => 'var:preset|color|ui-black' ) ),
				),
				'spacing'  => array(
					'padding' => array(
						'top'    => 'var:preset|spacing|30',
						'right'  => 'var:preset|spacing|30',
						'bottom' => 'var:preset|spacing|30',
						'left'   => 'var:preset|spacing|30',
					),
				),
			),
			'layout'          => array( 'type' => 'constrained' ),
		),
		'innerHTML'    => '',
		'innerContent' => array(),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'core/paragraph',
				'attrs'       => array(),
				'innerHTML'   => '<p>Beige box</p>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/group' ), $beige_group, $post );
	assert_contains( 'background-color:#f0f0e6', $html, 'group: named theme-origin background resolves' );
	assert_contains( 'padding:24px', $html, 'group: spacing|30 resolves to 24px' );
	assert_contains( 'Beige box', $html, 'group: inner paragraph present' );
	$beige_bg_count = substr_count( $html, 'background-color:#f0f0e6' );
	assert_true( 1 === $beige_bg_count, 'group: background not duplicated' );
	assert_true( 1 === substr_count( $html, 'padding:24px' ) + substr_count( $html, 'padding-top:24px' ), 'group: padding not duplicated' );

	// Buttons — side by side + outline.
	Dark_Mode_Registry::reset();
	$buttons_block = array(
		'blockName'   => 'core/buttons',
		'attrs'       => array( 'layout' => array( 'type' => 'flex', 'justifyContent' => 'left' ) ),
		'innerHTML'   => '',
		'innerContent' => array(),
		'innerBlocks'  => array(
			array(
				'blockName'   => 'core/button',
				'attrs'       => array(),
				'innerHTML'   => '<div class="wp-block-button"><a class="wp-block-button__link">Solid</a></div>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
			array(
				'blockName'   => 'core/button',
				'attrs'       => array( 'className' => 'is-style-outline' ),
				'innerHTML'   => '<div class="wp-block-button is-style-outline"><a class="wp-block-button__link">Outline</a></div>',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/buttons' ), $buttons_block, $post );
	assert_contains( '<tr', $html, 'buttons: table row' );
	assert_contains( 'Solid', $html, 'buttons: solid label' );
	assert_contains( 'Outline', $html, 'buttons: outline label' );
	assert_contains( 'background-color:transparent', $html, 'buttons: outline transparent bg' );
	assert_contains( 'border:2px solid', $html, 'buttons: outline border' );
	assert_not_contains( 'href=""', $html, 'buttons: no empty href' );

	// Paragraph with custom text color.
	Dark_Mode_Registry::reset();
	$p_color = array_merge( $p_block, array(
		'attrs' => array(
			'style' => array(
				'color' => array( 'text' => '#990000' ),
			),
		),
	) );
	$html = call_user_func( Email_Block_Registry::get( 'core/paragraph' ), $p_color, $post );
	assert_contains( 'color:#990000', $html, 'paragraph: custom text color' );

	// Post date.
	Dark_Mode_Registry::reset();
	$date_cb = Email_Block_Registry::get( 'core/post-date' );
	assert_true( is_callable( $date_cb ), 'post-date: callback registered' );
	$html = call_user_func( $date_cb, array( 'blockName' => 'core/post-date', 'attrs' => array() ), $post );
	assert_contains( 'June 4, 2026', $html, 'post-date: renders published date' );
	assert_contains( '<p style=', $html, 'post-date: wrapped in styled paragraph' );
	assert_not_contains( '<a ', $html, 'post-date: no link by default' );

	$html = call_user_func( $date_cb, array( 'blockName' => 'core/post-date', 'attrs' => array( 'displayType' => 'modified' ) ), $post );
	assert_contains( 'June 5, 2026', $html, 'post-date: renders modified date' );

	$html = call_user_func( $date_cb, array( 'blockName' => 'core/post-date', 'attrs' => array( 'isLink' => true, 'textAlign' => 'center' ) ), $post );
	assert_contains( 'href=', $html, 'post-date: isLink wraps in anchor' );
	assert_contains( 'text-align:center', $html, 'post-date: honors textAlign' );

	// Button standalone.
	$btn_block = array(
		'blockName'   => 'core/button',
		'attrs'       => array( 'url' => 'https://example.com' ),
		'innerHTML'   => '<div class="wp-block-button"><a class="wp-block-button__link" href="https://example.com">Click me</a></div>',
		'innerContent' => array(),
		'innerBlocks'  => array(),
	);
	$html = call_user_func( Email_Block_Registry::get( 'core/button' ), $btn_block, $post );
	assert_contains( 'Click me', $html, 'button: preserves text' );
	assert_contains( 'https://example.com', $html, 'button: href preserved' );

	// -----------------------------------------------------------------------
	// Suite: Html_To_Email_Converter fallback
	// -----------------------------------------------------------------------

	echo "\n=== Html_To_Email_Converter ===\n";

	$conv = new Html_To_Email_Converter();

	$html = $conv->convert( '' );
	assert_true( '' === $html, 'fallback: empty input → empty output' );

	$html = $conv->convert( '<a href="https://example.com">Link</a>' );
	assert_contains( '<p style=', $html, 'fallback: bare styled paragraph' );
	assert_contains( 'body-link', $html, 'fallback: body-link class' );
	assert_contains( 'color:#2b6dad', $html, 'fallback: inline link colour' );
	assert_not_contains( '<table width="100%"', $html, 'fallback: not in 100% table' );

	$html = $conv->convert( '<p>Hello world</p>' );
	assert_contains( 'Hello world', $html, 'fallback: preserves content' );
	assert_not_contains( '<!DOCTYPE', $html, 'fallback: no DOCTYPE leak' );
	assert_not_contains( '<html', $html, 'fallback: no <html> leak' );

	// Static helpers: float clamp + default max width.
	assert_true( 300 === Email_Block_Integration::clamp_float_width( 368 ), 'clamp_float_width: 368 -> 300' );
	assert_true( 300 === Email_Block_Integration::get_float_max_width(), 'get_float_max_width: default 300' );

	// -----------------------------------------------------------------------
	// Suite: guard tests (no legacy token leakage)
	// -----------------------------------------------------------------------

	echo "\n=== Guard tests ===\n";

	$all_callbacks = array(
		'core/paragraph' => array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>Text</p>', 'innerContent' => array(), 'innerBlocks' => array() ),
		'core/heading'   => array( 'blockName' => 'core/heading',   'attrs' => array( 'level' => 2 ), 'innerHTML' => '<h2>Heading</h2>', 'innerContent' => array(), 'innerBlocks' => array() ),
		'core/spacer'    => array( 'blockName' => 'core/spacer',    'attrs' => array( 'height' => '20px' ), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array() ),
		'core/separator' => array( 'blockName' => 'core/separator', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array() ),
		'core/image'     => array( 'blockName' => 'core/image',     'attrs' => array( 'url' => 'https://example.com/img.jpg', 'alt' => '' ), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array() ),
	);

	foreach ( $all_callbacks as $block_name => $block ) {
		$cb   = Email_Block_Registry::get( $block_name );
		if ( ! $cb ) { continue; }
		$html = call_user_func( $cb, $block, $post );
		assert_not_contains( '{{PRC_EMAIL_', $html, "$block_name: no PRC_EMAIL_ tokens" );
		assert_not_contains( '<!DOCTYPE', $html, "$block_name: no DOCTYPE" );
		assert_not_contains( '<html', $html, "$block_name: no <html> tag" );
		assert_not_contains( '<head', $html, "$block_name: no <head> tag" );
	}

	// -----------------------------------------------------------------------
	// Summary
	// -----------------------------------------------------------------------

	echo "\n=== Summary ===\n";
	echo "Passed: $passed\n";
	echo "Failed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
