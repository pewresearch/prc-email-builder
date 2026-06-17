<?php
declare(strict_types=1);
/**
 * Newsletter list taxonomy: Mailchimp audience/segment on terms and campaign override.
 *
 * @package    PRC\Platform\Email_Builder
 */

namespace PRC\Platform\Email_Builder;

/**
 * Term admin UI for prc_newsletter_list and server-side audience/segment override
 * when a campaign has an assigned list term.
 */
class Newsletter_List {
	const NONCE_ACTION = 'prc_newsletter_list_term_meta';
	const NONCE_FIELD  = 'prc_newsletter_list_term_meta_nonce';
	const SCRIPT_HANDLE = 'prc-email-builder-term-admin';

	public function __construct( Loader $loader ) {
		$taxonomy = Post_Type::TAXONOMY;

		$loader->add_action( "{$taxonomy}_add_form_fields", $this, 'render_add_form_fields' );
		$loader->add_action( "{$taxonomy}_edit_form_fields", $this, 'render_edit_form_fields' );
		$loader->add_action( "created_{$taxonomy}", $this, 'save_term_meta' );
		$loader->add_action( "edited_{$taxonomy}", $this, 'save_term_meta' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_term_admin_assets' );
		$loader->add_action(
			'rest_after_insert_' . Post_Type::CAMPAIGN_POST_TYPE,
			$this,
			'override_campaign_audience_from_list',
			9,
			1
		);
	}

	/**
	 * Mailchimp audience + segment fields on the "Add Newsletter List" form.
	 */
	public function render_add_form_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<div class="form-field prc-newsletter-list-mailchimp-wrap">';
		$this->render_mailchimp_fields();
		echo '</div>';
	}

	/**
	 * Mailchimp audience + segment fields on the "Edit Newsletter List" form.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_edit_form_fields( \WP_Term $term ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$audience_id = (string) get_term_meta( $term->term_id, 'prc_newsletter_list_audience_id', true );
		$segment_id  = (string) get_term_meta( $term->term_id, 'prc_newsletter_list_segment_id', true );

		echo '<tr class="form-field prc-newsletter-list-mailchimp-wrap">';
		echo '<th scope="row"><label>' . esc_html__( 'Mailchimp', 'prc-email-builder' ) . '</label></th>';
		echo '<td>';
		$this->render_mailchimp_fields( $audience_id, $segment_id );
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * @param string $audience_id Saved audience ID (edit form).
	 * @param string $segment_id  Saved segment ID (edit form).
	 */
	private function render_mailchimp_fields( string $audience_id = '', string $segment_id = '' ): void {
		$mailchimp = new Mailchimp();
		$audiences = $mailchimp->get_audiences();

		if ( is_wp_error( $audiences ) ) {
			echo '<p class="description">' . esc_html( $audiences->get_error_message() ) . '</p>';
			return;
		}

		if ( ! $mailchimp->is_connected() || empty( $audiences ) ) {
			echo '<p class="description">' . esc_html__(
				'Mailchimp is not connected or has no audiences. Configure the API key in Newsletter Builder Settings.',
				'prc-email-builder'
			) . '</p>';
			return;
		}

		echo '<p>';
		echo '<label for="prc_newsletter_list_audience_id">' . esc_html__( 'Audience', 'prc-email-builder' ) . '</label><br />';
		echo '<select name="prc_newsletter_list_audience_id" id="prc_newsletter_list_audience_id" class="prc-newsletter-list-audience">';
		echo '<option value="">' . esc_html__( '— Select audience —', 'prc-email-builder' ) . '</option>';
		foreach ( $audiences as $id => $name ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $id ),
				selected( $audience_id, (string) $id, false ),
				esc_html( (string) $name )
			);
		}
		echo '</select>';
		echo '</p>';

		$segments = [];
		if ( '' !== $audience_id ) {
			$fetched = $mailchimp->get_segments( $audience_id );
			if ( ! is_wp_error( $fetched ) ) {
				$segments = $fetched;
			}
		}

		echo '<p class="prc-newsletter-list-segment-field">';
		echo '<label for="prc_newsletter_list_segment_id">' . esc_html__( 'Segment (optional)', 'prc-email-builder' ) . '</label><br />';
		echo '<span class="prc-newsletter-list-segment-controls">';
		echo '<select name="prc_newsletter_list_segment_id" id="prc_newsletter_list_segment_id" class="prc-newsletter-list-segment" data-saved-segment="' . esc_attr( $segment_id ) . '">';
		if ( '' === $audience_id ) {
			echo '<option value="" disabled selected>' . esc_html__( 'Select an audience first', 'prc-email-builder' ) . '</option>';
		} else {
			echo '<option value="">' . esc_html__( 'Entire audience', 'prc-email-builder' ) . '</option>';
			foreach ( $segments as $segment ) {
				$seg_id = (string) ( $segment['id'] ?? '' );
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $seg_id ),
					selected( $segment_id, $seg_id, false ),
					esc_html(
						sprintf(
							'%s (%s)',
							(string) ( $segment['name'] ?? '' ),
							number_format_i18n( (int) ( $segment['member_count'] ?? 0 ) )
						)
					)
				);
			}
		}
		echo '</select>';
		echo '<span class="spinner" id="prc_newsletter_list_segment_spinner"></span>';
		echo '</span>';
		echo '<p class="description">' . esc_html__(
			'When this list is selected on a campaign, audience and segment are locked to these values.',
			'prc-email-builder'
		) . '</p>';
		echo '</p>';
	}

	/**
	 * Persist Mailchimp audience/segment meta when a list term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if (
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				self::NONCE_ACTION
			)
		) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$audience_id = isset( $_POST['prc_newsletter_list_audience_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST['prc_newsletter_list_audience_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$segment_id  = isset( $_POST['prc_newsletter_list_segment_id'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( (string) $_POST['prc_newsletter_list_segment_id'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( '' !== $segment_id && '' === $audience_id ) {
			$segment_id = '';
		}

		update_term_meta( $term_id, 'prc_newsletter_list_audience_id', $audience_id );
		update_term_meta( $term_id, 'prc_newsletter_list_segment_id', $segment_id );
	}

	/**
	 * Enqueue segment loader on Newsletter Lists taxonomy admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_term_admin_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, [ 'edit-tags.php', 'term.php' ], true ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		if ( Post_Type::TAXONOMY !== $taxonomy ) {
			return;
		}

		$asset_file = PRC_EMAIL_BUILDER_DIR . '/build/term-admin/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/term-admin/index.js', PRC_EMAIL_BUILDER_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'prcEmailBuilderTermAdmin',
			[
				'restNamespace' => REST_API::NAMESPACE,
				'nonce'         => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * When a campaign has a newsletter list term, overwrite audience/segment post meta
	 * and enforce a single assigned term. No list term → leave meta as the user set it.
	 *
	 * @hook rest_after_insert_prc_email_campaign (priority 9)
	 *
	 * @param \WP_Post $post Saved campaign post.
	 */
	public function override_campaign_audience_from_list( \WP_Post $post ): void {
		if ( ! Post_Type::is_campaign_post( $post ) ) {
			return;
		}

		$term_ids = wp_get_object_terms(
			$post->ID,
			Post_Type::TAXONOMY,
			[
				'fields' => 'ids',
			]
		);

		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return;
		}

		$term_ids = array_values( array_map( 'intval', $term_ids ) );
		$term_id  = (int) $term_ids[0];

		if ( count( $term_ids ) > 1 ) {
			wp_set_object_terms( $post->ID, [ $term_id ], Post_Type::TAXONOMY, false );
		}

		$audience_id = (string) get_term_meta( $term_id, 'prc_newsletter_list_audience_id', true );
		$segment_id  = (string) get_term_meta( $term_id, 'prc_newsletter_list_segment_id', true );

		update_post_meta( $post->ID, 'prc_email_mailchimp_audience_id', $audience_id );
		update_post_meta( $post->ID, 'prc_email_mailchimp_segment_id', $segment_id );
	}
}
