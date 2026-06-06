<?php
declare(strict_types=1);
/**
 * Regression coverage for email body template resolution.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-template-resolver.php
 */

namespace {
	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type,
			public readonly string $post_status = 'draft'
		) {}
	}
}

namespace PRC\Platform\Email_Builder {
	if ( ! defined( 'PRC_EMAIL_BUILDER_DIR' ) ) {
		define( 'PRC_EMAIL_BUILDER_DIR', dirname( __DIR__ ) );
	}

	require_once PRC_EMAIL_BUILDER_DIR . '/includes/class-post-type.php';
	require_once PRC_EMAIL_BUILDER_DIR . '/includes/class-template-registry.php';
	require_once PRC_EMAIL_BUILDER_DIR . '/includes/class-template-resolver.php';

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			fwrite(
				STDERR,
				sprintf(
					"FAIL: %s (expected %s, got %s)\n",
					$message,
					var_export( $expected, true ),
					var_export( $actual, true )
				)
			);
			exit( 1 );
		}
	}

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	/**
	 * Seed Template_Registry with deterministic fixtures.
	 *
	 * @param array<string, array<string, string>> $templates Templates keyed by slug.
	 */
	function seed_template_registry( array $templates ): void {
		$ref  = new \ReflectionClass( Template_Registry::class );
		$prop = $ref->getProperty( 'cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, $templates );
	}

	$campaign = new \WP_Post( 1, Post_Type::CAMPAIGN_POST_TYPE );
	$txn      = new \WP_Post( 2, Post_Type::TRANSACTIONAL_POST_TYPE );

	$default_tpl = [
		'slug'        => 'default',
		'label'       => 'Default',
		'description' => '',
		'audience'    => '',
		'segment'     => '',
		'path'        => PRC_EMAIL_BUILDER_DIR . '/templates/default.php',
	];

	$transactional_tpl = [
		'slug'        => 'transactional',
		'label'       => 'Transactional',
		'description' => '',
		'audience'    => '',
		'segment'     => '',
		'path'        => PRC_EMAIL_BUILDER_DIR . '/templates/transactional.php',
	];

	$audience_tpl = [
		'slug'        => 'daily-brief',
		'label'       => 'Daily Brief',
		'description' => '',
		'audience'    => 'aud_123',
		'segment'     => 'seg_456',
		'path'        => PRC_EMAIL_BUILDER_DIR . '/templates/daily-brief.php',
	];

	$posts = [
		1 => $campaign,
		2 => $txn,
	];

	function get_post( int $post_id ) {
		global $test_posts;
		return $test_posts[ $post_id ] ?? null;
	}

	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		global $test_meta;
		return $test_meta[ $post_id ][ $key ] ?? '';
	}

	$GLOBALS['test_posts'] = $posts;
	$GLOBALS['test_meta']  = [];

	seed_template_registry(
		[
			'default'       => $default_tpl,
			'transactional' => $transactional_tpl,
			'daily-brief'   => $audience_tpl,
		]
	);

	assert_same(
		'transactional',
		Template_Resolver::resolve( 2 ),
		'Transactional post should auto-select the transactional template.'
	);

	$GLOBALS['test_meta'][ 2 ] = [ 'prc_email_template_slug' => 'default' ];
	assert_same(
		'transactional',
		Template_Resolver::resolve( 2 ),
		'Transactional post should ignore a default template override.'
	);

	$GLOBALS['test_meta'] = [
		1 => [
			'prc_email_mailchimp_audience_id' => 'aud_123',
			'prc_email_mailchimp_segment_id'  => 'seg_456',
		],
	];
	assert_same(
		'daily-brief',
		Template_Resolver::resolve( 1 ),
		'Campaign post should auto-match audience + segment.'
	);

	$GLOBALS['test_meta'][ 1 ]['prc_email_template_slug'] = 'default';
	assert_same(
		'default',
		Template_Resolver::resolve( 1 ),
		'Campaign post manual template override should win.'
	);

	$GLOBALS['test_meta'] = [];
	assert_same(
		'default',
		Template_Resolver::resolve( 1 ),
		'Campaign post without audience meta should fall back to default.'
	);

	seed_template_registry( [ 'default' => $default_tpl ] );
	assert_same(
		'default',
		Template_Resolver::resolve( 2 ),
		'Transactional post should fall back to default when transactional template is missing.'
	);

	$transactional_html = (string) file_get_contents( $transactional_tpl['path'] );
	assert_true(
		str_contains( $transactional_html, '/about/privacy/' ),
		'Transactional template should include Privacy Policy link.'
	);
	assert_true(
		str_contains( $transactional_html, '/about/terms-and-conditions/' ),
		'Transactional template should include Terms of Use link.'
	);
	assert_true(
		! str_contains( $transactional_html, '*|UNSUB|*' )
		&& ! str_contains( $transactional_html, '*|UPDATE_PROFILE|*' )
		&& ! str_contains( $transactional_html, '*|ARCHIVE|*' ),
		'Transactional template should not include Mailchimp merge tags.'
	);

	fwrite( STDOUT, "OK: template resolver tests passed.\n" );
}
