<?php
/**
 * Deal editing: the "Deal slots" panel on the product screen. The deal price
 * is the product's own General → Regular / Sale price.
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals_Admin' ) ) {

	/**
	 * Product screen panel and save.
	 */
	final class Lafka_Deals_Admin {

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tabs' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}

		/**
		 * Add the Deal slots tab; deals have no shipping or attributes of their own.
		 *
		 * @param array<string, array<string,mixed>> $tabs Tabs.
		 * @return array<string, array<string,mixed>>
		 */
		public static function tabs( $tabs ): array {
			$tabs = (array) $tabs;
			foreach ( array( 'shipping', 'attribute', 'variations' ) as $key ) {
				if ( isset( $tabs[ $key ]['class'] ) ) {
					$tabs[ $key ]['class'][] = 'hide_if_' . Lafka_Deals::TYPE;
				}
			}
			$tabs['lafka_deal'] = array(
				'label'    => __( 'Deal slots', 'lafka-plugin' ),
				'target'   => 'lafka_deal_data',
				'class'    => array( 'show_if_' . Lafka_Deals::TYPE ),
				'priority' => 15,
			);
			return $tabs;
		}

		/**
		 * The attribute terms a slot can lock, as "taxonomy|term" => label.
		 *
		 * @return array<string, array<string,string>> Grouped by attribute label.
		 */
		private static function lockable_terms(): array {
			$groups = array();
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
				$terms    = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				if ( ! is_array( $terms ) || array() === $terms ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$groups[ $attribute->attribute_label ][ $taxonomy . '|' . $term->slug ] = $term->name;
				}
			}
			return $groups;
		}

		/**
		 * One slot row (also the template for "Add slot", with index __i__).
		 *
		 * @param string              $index      Row index.
		 * @param array<string,mixed> $slot       Slot (defaults for a new row).
		 * @param array<int,string>   $categories Category id => name.
		 * @param array               $lockable   lockable_terms().
		 * @return void
		 */
		private static function row( string $index, array $slot, array $categories, array $lockable ): void {
			$name   = static function ( string $field ) use ( $index ): string {
				return 'lafka_deal_slots[' . $index . '][' . $field . ']';
			};
			$locked = array();
			foreach ( (array) ( $slot['attributes'] ?? array() ) as $taxonomy => $term ) {
				$locked[] = $taxonomy . '|' . $term;
			}
			?>
			<div class="lafka-deal-slot" data-lafka-deal-slot>
				<p class="form-field">
					<label><?php esc_html_e( 'Label', 'lafka-plugin' ); ?></label>
					<input type="text" class="short" name="<?php echo esc_attr( $name( 'label' ) ); ?>" value="<?php echo esc_attr( (string) ( $slot['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Pizza 1', 'lafka-plugin' ); ?>">
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Categories', 'lafka-plugin' ); ?></label>
					<select class="wc-enhanced-select" multiple="multiple" style="width:50%" name="<?php echo esc_attr( $name( 'categories' ) ); ?>[]" data-placeholder="<?php esc_attr_e( 'Any item in these categories', 'lafka-plugin' ); ?>">
						<?php foreach ( $categories as $term_id => $label ) : ?>
							<option value="<?php echo esc_attr( (string) $term_id ); ?>" <?php selected( in_array( (int) $term_id, (array) ( $slot['categories'] ?? array() ), true ) ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php
				foreach (
					array(
						'products' => __( 'Also these items', 'lafka-plugin' ),
						'exclude'  => __( 'Never these items', 'lafka-plugin' ),
					) as $field => $label
				) :
					?>
					<p class="form-field">
						<label><?php echo esc_html( $label ); ?></label>
						<select class="wc-product-search" multiple="multiple" style="width:50%" name="<?php echo esc_attr( $name( $field ) ); ?>[]" data-action="woocommerce_json_search_products" data-placeholder="<?php esc_attr_e( 'Search for an item…', 'lafka-plugin' ); ?>">
							<?php
							foreach ( (array) ( $slot[ $field ] ?? array() ) as $product_id ) {
								$product = wc_get_product( (int) $product_id );
								if ( $product ) {
									echo '<option value="' . esc_attr( (string) $product->get_id() ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
								}
							}
							?>
						</select>
					</p>
				<?php endforeach; ?>
				<p class="form-field">
					<label><?php esc_html_e( 'Lock options', 'lafka-plugin' ); ?></label>
					<select class="wc-enhanced-select" multiple="multiple" style="width:50%" name="<?php echo esc_attr( $name( 'attributes' ) ); ?>[]" data-placeholder="<?php esc_attr_e( 'e.g. Size: Medium', 'lafka-plugin' ); ?>">
						<?php foreach ( $lockable as $group => $terms ) : ?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $terms as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $locked, true ) ); ?>><?php echo esc_html( $group . ': ' . $label ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-field">
					<label><?php esc_html_e( 'Rules', 'lafka-plugin' ); ?></label>
					<label style="float:none;width:auto;margin:0 1.5em 0 0;"><input type="checkbox" value="1" name="<?php echo esc_attr( $name( 'required' ) ); ?>" <?php checked( ! isset( $slot['required'] ) || ! empty( $slot['required'] ) ); ?>> <?php esc_html_e( 'Required', 'lafka-plugin' ); ?></label>
					<label style="float:none;width:auto;margin:0;"><input type="checkbox" value="1" name="<?php echo esc_attr( $name( 'upcharge' ) ); ?>" <?php checked( ! empty( $slot['upcharge'] ) ); ?>> <?php esc_html_e( 'Premium items pay the difference over the cheapest choice', 'lafka-plugin' ); ?></label>
				</p>
				<p class="form-field"><button type="button" class="button-link button-link-delete" data-lafka-deal-remove><?php esc_html_e( 'Remove slot', 'lafka-plugin' ); ?></button></p>
			</div>
			<?php
		}

		/**
		 * The Deal slots panel.
		 *
		 * @return void
		 */
		public static function panel(): void {
			global $product_object;
			$slots      = $product_object instanceof WC_Product ? Lafka_Deals::get_slots( $product_object ) : array();
			$categories = array();
			$terms      = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$categories[ (int) $term->term_id ] = $term->name;
			}
			$lockable = self::lockable_terms();
			?>
			<div id="lafka_deal_data" class="panel woocommerce_options_panel hidden">
				<p class="form-field"><span class="description">
					<?php esc_html_e( 'The deal price is this product\'s Regular / Sale price (General tab). Each slot is one item the customer chooses; each chosen item becomes its own cart line with its own options, and the deal price is split across them. Extra toppings and other add-ons are charged on top.', 'lafka-plugin' ); ?>
				</span></p>
				<div data-lafka-deal-slots data-next-index="<?php echo esc_attr( (string) max( 2, count( $slots ) ) ); ?>">
					<?php
					$rows = array() === $slots ? array( array( 'label' => __( 'Item 1', 'lafka-plugin' ) ), array( 'label' => __( 'Item 2', 'lafka-plugin' ) ) ) : $slots;
					foreach ( $rows as $i => $slot ) {
						self::row( (string) $i, $slot, $categories, $lockable );
					}
					?>
				</div>
				<script type="text/html" id="tmpl-lafka-deal-slot">
					<?php self::row( '__i__', array(), $categories, $lockable ); ?>
				</script>
				<p class="form-field"><button type="button" class="button" data-lafka-deal-add><?php esc_html_e( 'Add slot', 'lafka-plugin' ); ?></button></p>
				<?php wp_nonce_field( 'lafka_deal_slots', 'lafka_deal_slots_nonce' ); ?>
			</div>
			<?php
		}

		/**
		 * Save the slots of a deal.
		 *
		 * @param WC_Product $product Product being saved.
		 * @return void
		 */
		public static function save( $product ): void {
			if ( ! $product instanceof WC_Product || ! $product->is_type( Lafka_Deals::TYPE ) ) {
				return;
			}
			if ( ! isset( $_POST['lafka_deal_slots_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lafka_deal_slots_nonce'] ) ), 'lafka_deal_slots' ) ) {
				return;
			}
			$rows = isset( $_POST['lafka_deal_slots'] ) && is_array( $_POST['lafka_deal_slots'] ) ? map_deep( wp_unslash( $_POST['lafka_deal_slots'] ), 'sanitize_text_field' ) : array();
			unset( $rows['__i__'] );
			foreach ( $rows as $i => $row ) {
				$attributes = array();
				foreach ( (array) ( $row['attributes'] ?? array() ) as $pair ) {
					$parts = explode( '|', (string) $pair, 2 );
					if ( 2 === count( $parts ) ) {
						$attributes[ $parts[0] ] = $parts[1];
					}
				}
				$rows[ $i ]['attributes'] = $attributes;
				$rows[ $i ]['required']   = ! empty( $row['required'] );
			}
			$product->update_meta_data( Lafka_Deals::SLOTS_META, Lafka_Deals::normalize_slots( array_values( $rows ) ) );
		}

		/**
		 * The panel script: add / remove slot rows, show the price fields.
		 *
		 * @param string $hook Admin page.
		 * @return void
		 */
		public static function assets( $hook ): void {
			if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
				return;
			}
			$rel = lafka_plugin_script_path( 'assets/js/lafka-deals-admin.min.js' );
			wp_enqueue_script( 'lafka-deals-admin', plugins_url( $rel, LAFKA_PLUGIN_FILE ), array( 'jquery', 'wc-enhanced-select' ), lafka_plugin_asset_version( $rel ), true );
		}
	}
}
