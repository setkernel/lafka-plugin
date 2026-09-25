<?php
/**
 * Category tagline (GX4): one short line under a menu category heading.
 *
 * Operators write it on Products → Categories → Add / Edit ("Tagline"), e.g.
 * "Clear-coated fries, cheese curds and gravy." Plain text, up to 140
 * characters, stored as term meta `lafka_tagline` on product_cat. Themes show
 * it under the category's section heading; the counter layout falls back to
 * the first sentence of the category description when it is empty.
 *
 * Public API:
 *   · lafka_get_category_tagline( WP_Term|int $term ): string — '' when unset.
 *   · filter `lafka_category_tagline_meta` ( string $line, int $term_id ).
 *   · term meta `lafka_tagline` (registered: single string, show_in_rest,
 *     writable by users who can manage_product_terms).
 *   · Saving fires `lafka_menu_data_changed` (menu caches listen).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_CATEGORY_TAGLINE_META' ) ) {
	define( 'LAFKA_CATEGORY_TAGLINE_META', 'lafka_tagline' );
}

if ( ! defined( 'LAFKA_CATEGORY_TAGLINE_MAX' ) ) {
	define( 'LAFKA_CATEGORY_TAGLINE_MAX', 140 );
}

if ( ! function_exists( 'lafka_sanitize_category_tagline' ) ) {
	/**
	 * Plain text, one line, at most LAFKA_CATEGORY_TAGLINE_MAX characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	function lafka_sanitize_category_tagline( $value ): string {
		$line = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return function_exists( 'mb_substr' ) ? mb_substr( $line, 0, LAFKA_CATEGORY_TAGLINE_MAX ) : substr( $line, 0, LAFKA_CATEGORY_TAGLINE_MAX );
	}
}

if ( ! function_exists( 'lafka_get_category_tagline' ) ) {
	/**
	 * The tagline of a product category ('' when unset).
	 *
	 * @param mixed $term WP_Term or term id.
	 * @return string
	 */
	function lafka_get_category_tagline( $term ): string {
		$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : (int) $term;
		if ( $term_id <= 0 ) {
			return '';
		}
		$line = lafka_sanitize_category_tagline( get_term_meta( $term_id, LAFKA_CATEGORY_TAGLINE_META, true ) );

		/**
		 * Filter a category's saved tagline.
		 *
		 * @since 10.2.0
		 * @param string $line    Tagline (plain text, '' when unset).
		 * @param int    $term_id Term id.
		 */
		return lafka_sanitize_category_tagline( apply_filters( 'lafka_category_tagline_meta', $line, $term_id ) );
	}
}

if ( ! function_exists( 'lafka_category_tagline_register_meta' ) ) {
	/**
	 * Register the term meta (REST-visible, editor-writable).
	 *
	 * @return void
	 */
	function lafka_category_tagline_register_meta() {
		register_term_meta(
			'product_cat',
			LAFKA_CATEGORY_TAGLINE_META,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'description'       => __( 'Short line under the category heading.', 'lafka-plugin' ),
				'show_in_rest'      => true,
				'sanitize_callback' => 'lafka_sanitize_category_tagline',
				'auth_callback'     => static function () {
					return current_user_can( 'manage_product_terms' );
				},
			)
		);
	}
}

if ( ! function_exists( 'lafka_category_tagline_input' ) ) {
	/**
	 * The input + description markup.
	 *
	 * @param string $value Current value.
	 * @return string
	 */
	function lafka_category_tagline_input( string $value ): string {
		return sprintf(
			'<input type="hidden" name="lafka_tagline_present" value="1"><input type="text" class="regular-text" id="lafka_tagline" name="lafka_tagline" value="%1$s" maxlength="%2$d"><p class="description">%3$s</p>',
			esc_attr( $value ),
			(int) LAFKA_CATEGORY_TAGLINE_MAX,
			esc_html__( 'Short line under the heading, e.g. what comes on it. Plain text, up to 140 characters. Leave empty to show nothing (some layouts then use the first sentence of the description).', 'lafka-plugin' )
		);
	}
}

if ( ! function_exists( 'lafka_category_tagline_add_field' ) ) {
	/**
	 * Tagline on the "Add new category" form.
	 *
	 * @return void
	 */
	function lafka_category_tagline_add_field() {
		?>
		<div class="form-field">
			<label for="lafka_tagline"><?php esc_html_e( 'Tagline (optional)', 'lafka-plugin' ); ?></label>
			<?php echo lafka_category_tagline_input( '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in lafka_category_tagline_input(). ?>
		</div>
		<?php
	}
}

if ( ! function_exists( 'lafka_category_tagline_edit_field' ) ) {
	/**
	 * Tagline on the "Edit category" form.
	 *
	 * @param object $term Term being edited.
	 * @return void
	 */
	function lafka_category_tagline_edit_field( $term ) {
		$value = isset( $term->term_id ) ? lafka_sanitize_category_tagline( get_term_meta( (int) $term->term_id, LAFKA_CATEGORY_TAGLINE_META, true ) ) : '';
		?>
		<tr class="form-field">
			<th scope="row"><label for="lafka_tagline"><?php esc_html_e( 'Tagline (optional)', 'lafka-plugin' ); ?></label></th>
			<td><?php echo lafka_category_tagline_input( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in lafka_category_tagline_input(). ?></td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'lafka_category_tagline_save' ) ) {
	/**
	 * Save the tagline (created_ / edited_product_cat). Needs the core
	 * term-form nonce and manage_product_terms, and acts only when the field
	 * was on the submitted form.
	 *
	 * @param int $term_id Term id.
	 * @return void
	 */
	function lafka_category_tagline_save( $term_id ) {
		if ( ! function_exists( 'lafka_verify_term_form_nonce' ) || ! lafka_verify_term_form_nonce( $term_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) || empty( $_POST['lafka_tagline_present'] ) ) {
			return;
		}
		$line = isset( $_POST['lafka_tagline'] ) ? lafka_sanitize_category_tagline( wp_unslash( $_POST['lafka_tagline'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by lafka_sanitize_category_tagline().
		if ( '' === $line ) {
			delete_term_meta( (int) $term_id, LAFKA_CATEGORY_TAGLINE_META );
		} else {
			update_term_meta( (int) $term_id, LAFKA_CATEGORY_TAGLINE_META, $line );
		}
		do_action( 'lafka_menu_data_changed' );
	}
}

if ( ! function_exists( 'lafka_category_tagline_init' ) ) {
	/**
	 * Wire the meta registration, the admin field and the save.
	 *
	 * @return void
	 */
	function lafka_category_tagline_init() {
		add_action( 'init', 'lafka_category_tagline_register_meta' );
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			require_once dirname( __DIR__ ) . '/admin/lafka-term-form-nonce.php';
			add_action( 'product_cat_add_form_fields', 'lafka_category_tagline_add_field', 20 );
			add_action( 'product_cat_edit_form_fields', 'lafka_category_tagline_edit_field', 20 );
		}
		add_action( 'created_product_cat', 'lafka_category_tagline_save' );
		add_action( 'edited_product_cat', 'lafka_category_tagline_save' );
	}
}
