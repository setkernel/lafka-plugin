<?php
/**
 * GX3: optional per-category FAQ (menu category landing content).
 *
 * Operators add question/answer pairs on Products → Categories → (edit) —
 * a "repeater-lite": the saved pairs plus a few empty rows, blank rows are
 * dropped on save. Stored as term meta `_lafka_term_faqs` (list of
 * array{q,a}).
 *
 * Consumers:
 *   - the theme renders the visible FAQ under the category grid via
 *     lafka_seo_get_term_faqs() (lafka-theme partials/menu-category-faq.php);
 *   - lafka_schema_faq() emits FAQPage JSON-LD on that category archive —
 *     only when the pairs are actually filled, so schema always mirrors
 *     visible, operator-written content;
 *   - /llms-full.txt lists them under their category.
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

/** Term meta key holding the FAQ pairs. */
const LAFKA_TERM_FAQ_META = '_lafka_term_faqs';

/** Maximum pairs stored per category. */
const LAFKA_TERM_FAQ_MAX = 12;

if ( ! function_exists( 'lafka_seo_normalize_faqs' ) ) {
	/**
	 * Keep only complete {q, a} pairs, trimmed, capped.
	 *
	 * @param mixed $rows Raw rows.
	 * @return list<array{q:string,a:string}>
	 */
	function lafka_seo_normalize_faqs( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = isset( $row['q'] ) && is_scalar( $row['q'] ) ? trim( (string) $row['q'] ) : '';
			$a = isset( $row['a'] ) && is_scalar( $row['a'] ) ? trim( (string) $row['a'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = array(
				'q' => $q,
				'a' => $a,
			);
			if ( count( $out ) >= LAFKA_TERM_FAQ_MAX ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_seo_get_term_faqs' ) ) {
	/**
	 * The filled FAQ pairs of a product category (answers may hold basic
	 * HTML — render through wp_kses_post()).
	 *
	 * @param int $term_id Term id.
	 * @return list<array{q:string,a:string}>
	 */
	function lafka_seo_get_term_faqs( int $term_id ): array {
		if ( $term_id <= 0 || ! function_exists( 'get_term_meta' ) ) {
			return array();
		}
		$faqs = lafka_seo_normalize_faqs( get_term_meta( $term_id, LAFKA_TERM_FAQ_META, true ) );

		/**
		 * Filter a category's FAQ pairs.
		 *
		 * @since 10.2.0
		 * @param list<array{q:string,a:string}> $faqs    Pairs.
		 * @param int                            $term_id Term id.
		 */
		return lafka_seo_normalize_faqs( apply_filters( 'lafka_term_faqs', $faqs, $term_id ) );
	}
}

if ( ! function_exists( 'lafka_term_faq_rows_html' ) ) {
	/**
	 * Input rows for the FAQ repeater: saved pairs + empty rows.
	 *
	 * @param list<array{q:string,a:string}> $faqs  Saved pairs.
	 * @param int                            $blank Extra empty rows.
	 * @return string
	 */
	function lafka_term_faq_rows_html( array $faqs, int $blank = 3 ): string {
		$rows = $faqs;
		$room = max( 0, min( $blank, LAFKA_TERM_FAQ_MAX - count( $faqs ) ) );
		for ( $i = 0; $i < $room; $i++ ) {
			$rows[] = array(
				'q' => '',
				'a' => '',
			);
		}
		$html = '<input type="hidden" name="lafka_term_faq_present" value="1">';
		foreach ( $rows as $i => $row ) {
			$html .= sprintf(
				'<div class="lafka-term-faq-row" style="margin:0 0 12px;"><input type="text" class="large-text" name="lafka_term_faq[%1$d][q]" value="%2$s" placeholder="%3$s" aria-label="%3$s"><textarea class="large-text" rows="2" name="lafka_term_faq[%1$d][a]" placeholder="%4$s" aria-label="%4$s">%5$s</textarea></div>',
				(int) $i,
				esc_attr( $row['q'] ),
				esc_attr__( 'Question', 'lafka-plugin' ),
				esc_attr__( 'Answer', 'lafka-plugin' ),
				esc_textarea( $row['a'] )
			);
		}
		return $html;
	}
}

if ( ! function_exists( 'lafka_term_faq_add_field' ) ) {
	/**
	 * FAQ rows on the "Add new category" form.
	 *
	 * @return void
	 */
	function lafka_term_faq_add_field() {
		?>
		<div class="form-field">
			<label><?php esc_html_e( 'Category FAQ (optional)', 'lafka-plugin' ); ?></label>
			<?php echo lafka_term_faq_rows_html( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped in lafka_term_faq_rows_html(). ?>
			<p class="description"><?php esc_html_e( 'Real questions customers ask about this category (e.g. "Is the donair sauce made in-house?"). Shown under the category grid and published as FAQ structured data. Leave empty to show nothing.', 'lafka-plugin' ); ?></p>
		</div>
		<?php
	}
}

if ( ! function_exists( 'lafka_term_faq_edit_field' ) ) {
	/**
	 * FAQ rows on the "Edit category" form.
	 *
	 * @param object $term Term being edited.
	 * @return void
	 */
	function lafka_term_faq_edit_field( $term ) {
		$faqs = isset( $term->term_id ) ? lafka_seo_normalize_faqs( get_term_meta( (int) $term->term_id, LAFKA_TERM_FAQ_META, true ) ) : array();
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Category FAQ (optional)', 'lafka-plugin' ); ?></label></th>
			<td>
				<?php echo lafka_term_faq_rows_html( $faqs, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped in lafka_term_faq_rows_html(). ?>
				<p class="description"><?php esc_html_e( 'Real questions customers ask about this category. Shown under the category grid and published as FAQ structured data. Clear both fields of a row to remove it.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'lafka_term_faq_save' ) ) {
	/**
	 * Save the FAQ rows (edited_ / created_product_cat). Requires the core
	 * term-form nonce and the manage-terms capability; only acts when the
	 * repeater was on the submitted form.
	 *
	 * @param int $term_id Term id.
	 * @return void
	 */
	function lafka_term_faq_save( $term_id ) {
		if ( ! function_exists( 'lafka_verify_term_form_nonce' ) || ! lafka_verify_term_form_nonce( $term_id ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		if ( empty( $_POST['lafka_term_faq_present'] ) ) {
			return;
		}
		// Sanitised per field below; wp_unslash applied to the whole map first.
		$raw  = isset( $_POST['lafka_term_faq'] ) && is_array( $_POST['lafka_term_faq'] ) ? wp_unslash( $_POST['lafka_term_faq'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$rows = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'q' => sanitize_text_field( (string) ( $row['q'] ?? '' ) ),
				'a' => wp_kses_post( (string) ( $row['a'] ?? '' ) ),
			);
		}
		$faqs = lafka_seo_normalize_faqs( $rows );
		if ( empty( $faqs ) ) {
			delete_term_meta( (int) $term_id, LAFKA_TERM_FAQ_META );
		} else {
			update_term_meta( (int) $term_id, LAFKA_TERM_FAQ_META, $faqs );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'lafka_menu_data_changed' );
		}
	}
}

if ( function_exists( 'is_admin' ) && is_admin() ) {
	require_once dirname( __DIR__ ) . '/admin/lafka-term-form-nonce.php';
	add_action( 'product_cat_add_form_fields', 'lafka_term_faq_add_field', 30 );
	add_action( 'product_cat_edit_form_fields', 'lafka_term_faq_edit_field', 30 );
}
add_action( 'created_product_cat', 'lafka_term_faq_save' );
add_action( 'edited_product_cat', 'lafka_term_faq_save' );
