<?php
declare(strict_types=1);
/**
 * Public campaign email HTML preview endpoint.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Unauthenticated REST surface that serves rendered email HTML for published
 * campaigns. Kept separate from Preview so the public boundary is auditable.
 *
 * GET /prc-email-builder/v1/campaign-preview/{post_id}
 */
class Public_Email_Preview {

	/** Object-cache group for rendered public preview HTML. */
	const CACHE_GROUP = 'prc_email_builder_public_preview';

	/** Route path under REST_API::NAMESPACE. */
	const ROUTE = '/campaign-preview/(?P<post_id>\d+)';

	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
		$loader->add_filter( 'rest_pre_serve_request', $this, 'serve_raw_html', 10, 4 );
	}

	/**
	 * Register the public preview route.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_preview' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Serve raw text/html for our route instead of a JSON envelope.
	 *
	 * @hook rest_pre_serve_request
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function serve_raw_html( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof WP_REST_Request ) {
			return $served;
		}

		if ( ! $this->is_our_route( $request ) ) {
			return $served;
		}

		if ( ! $result instanceof WP_HTTP_Response ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served;
		}

		$status = (int) $result->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Cache-Control: public, max-age=300, s-maxage=900' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- full email HTML document.
		echo $data;

		return true;
	}

	/**
	 * GET /campaign-preview/{post_id}
	 *
	 * Returns the full email HTML document string (served raw by serve_raw_html).
	 * Failures are 404 WP_Error values so the route cannot enumerate drafts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	public function get_preview( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$check   = self::validate_previewable( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::not_found();
		}

		$cache_key = self::cache_key( $post_id, (string) $post->post_modified_gmt );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$html = Cached_Email_Html::resolve( $post_id );
		if ( is_wp_error( $html ) ) {
			return self::not_found();
		}

		$html = self::prepare_public_html( $html, $post_id );

		wp_cache_set( $cache_key, $html, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Whether a post may be served via the public preview route.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return true|WP_Error
	 */
	public static function validate_previewable( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return self::not_found();
		}

		if ( ! Post_Type::is_campaign_post( $post ) ) {
			return self::not_found();
		}

		if ( 'publish' !== $post->post_status ) {
			return self::not_found();
		}

		if ( post_password_required( $post ) ) {
			return self::not_found();
		}

		if ( Migration::is_migrated( $post_id ) ) {
			return self::not_found();
		}

		return true;
	}

	/**
	 * Scrub Mailchimp tags and inject noindex / base-target for public display.
	 *
	 * @param string $html    Full email HTML document.
	 * @param int    $post_id Campaign post ID.
	 * @return string
	 */
	public static function prepare_public_html( string $html, int $post_id ): string {
		$fallback = self::resolve_public_fallback_url( $post_id );
		$html     = self::neutralize_mailchimp_tags( $html, $fallback );
		return self::inject_public_head_tags( $html );
	}

	/**
	 * Replace list-management merge-tag hrefs and strip leftover *|...|* tokens.
	 *
	 * @param string $html         Email HTML.
	 * @param string $fallback_url Absolute URL for neutralized links.
	 * @return string
	 */
	public static function neutralize_mailchimp_tags( string $html, string $fallback_url ): string {
		$url = esc_url( $fallback_url );
		if ( '' === $url ) {
			$url = esc_url( home_url( '/' ) );
		}

		$html = preg_replace(
			'/href=(["\'])\*\|(?:UNSUB|UPDATE_PROFILE)\|\*\1/i',
			'href="' . $url . '" rel="nofollow noopener"',
			$html
		) ?? $html;

		$html = preg_replace( '/\*\|[A-Z0-9_]+\|\*/', '', $html ) ?? $html;

		return $html;
	}

	/**
	 * Inject robots noindex and base target="_blank" into &lt;head&gt;.
	 *
	 * @param string $html Email HTML.
	 * @return string
	 */
	public static function inject_public_head_tags( string $html ): string {
		$injection = '<meta name="robots" content="noindex,nofollow">' . "\n" . '<base target="_blank">';

		$updated = preg_replace( '/<head([^>]*)>/i', '<head$1>' . "\n" . $injection, $html, 1 );
		return is_string( $updated ) ? $updated : $html;
	}

	/**
	 * Prefer the newsletter list archive; fall back to the campaign permalink.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return string
	 */
	public static function resolve_public_fallback_url( int $post_id ): string {
		$terms = wp_get_object_terms(
			$post_id,
			Post_Type::TAXONOMY,
			array(
				'fields' => 'all',
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && isset( $terms[0] ) ) {
			$term_link = get_term_link( $terms[0] );
			if ( is_string( $term_link ) && '' !== $term_link ) {
				return $term_link;
			}
		}

		$permalink = get_permalink( $post_id );
		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Cache key keyed on post ID + modified GMT (self-invalidates on edit).
	 *
	 * @param int    $post_id            Post ID.
	 * @param string $post_modified_gmt  Post modified GMT datetime.
	 * @return string
	 */
	public static function cache_key( int $post_id, string $post_modified_gmt ): string {
		return $post_id . ':' . $post_modified_gmt;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	private function is_our_route( WP_REST_Request $request ): bool {
		$route = $request->get_route();
		return (bool) preg_match(
			'#^/' . preg_quote( REST_API::NAMESPACE, '#' ) . '/campaign-preview/\d+$#',
			$route
		);
	}

	/**
	 * Uniform 404 for every rejection path.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			'rest_not_found',
			'Campaign preview not found.',
			array( 'status' => 404 )
		);
	}
}
