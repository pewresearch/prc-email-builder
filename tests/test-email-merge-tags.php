<?php
declare(strict_types=1);
/**
 * View-online merge tag resolution coverage.
 *
 * Run with:
 * php plugins/prc-email-builder/tests/test-email-merge-tags.php
 */

namespace {
	class WP_Post {
		public function __construct(
			public readonly int $ID,
			public readonly string $post_type
		) {}
	}
}

namespace PRC\Platform\Email_Builder {
	require_once dirname( __DIR__ ) . '/includes/class-post-type.php';
	require_once dirname( __DIR__ ) . '/includes/email/class-email-merge-tags.php';

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

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

	function get_post( int $post_id ) {
		if ( 1 === $post_id ) {
			return new \WP_Post( 1, Post_Type::CAMPAIGN_POST_TYPE );
		}
		if ( 2 === $post_id ) {
			return new \WP_Post( 2, Post_Type::TRANSACTIONAL_POST_TYPE );
		}
		return null;
	}

	function get_permalink( int $post_id ) {
		if ( 1 === $post_id ) {
			return 'https://example.org/newsletter/the-briefing/my-campaign/';
		}
		return '';
	}

	function esc_url( string $url ): string {
		return $url;
	}

	$html = <<<'HTML'
<a href="*|ARCHIVE|*">View in browser</a>
<a href="{{ blog_post }}">Read online</a>
<a href="{{ webversion }}">Web version</a>
<a href="*|UNSUB|*">Unsubscribe</a>
HTML;

	$resolved = Email_Merge_Tags::resolve( $html, 1 );

	assert_true(
		! str_contains( $resolved, '*|ARCHIVE|*' ),
		'ARCHIVE merge tag should be replaced for campaign posts'
	);
	assert_true(
		! str_contains( $resolved, '{{ blog_post }}' ),
		'legacy blog_post tag should be replaced'
	);
	assert_true(
		! str_contains( $resolved, '{{ webversion }}' ),
		'legacy webversion tag should be replaced'
	);
	assert_true(
		str_contains( $resolved, 'https://example.org/newsletter/the-briefing/my-campaign/' ),
		'resolved HTML should contain the campaign permalink'
	);
	assert_true(
		str_contains( $resolved, '*|UNSUB|*' ),
		'Mailchimp unsubscribe tag should remain untouched'
	);

	assert_same(
		'https://example.org/newsletter/the-briefing/my-campaign/',
		Email_Merge_Tags::get_view_online_url( 1 ),
		'get_view_online_url should return the campaign permalink'
	);
	assert_same(
		'',
		Email_Merge_Tags::get_view_online_url( 2 ),
		'get_view_online_url should be empty for transactional posts'
	);

	$unchanged = Email_Merge_Tags::resolve( $html, 2 );
	assert_same(
		$html,
		$unchanged,
		'transactional posts should not have view-online tags replaced'
	);

	fwrite( STDOUT, "OK: email merge tag tests passed.\n" );
}
