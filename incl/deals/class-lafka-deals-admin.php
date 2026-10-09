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
					<?php esc_html_e( 'Each slot is one item the customer chooses; each chosen item becomes its own cart line with its own options, and the deal price is split across them. Extra toppings and other add-ons are charged on top.', 'lafka-plugin' ); ?>
				</span></p>
				<?php
				self::pricing_fields( $product_object );
				self::availability_fields( $product_object );
				self::condition_fields( $product_object );
				?>
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
		 * How the deal is priced: its own price, or a discount on the items chosen.
		 *
		 * @param WC_Product|null $product Product being edited.
		 * @return void
		 */
		private static function pricing_fields( $product ): void {
			$pricing = $product instanceof WC_Product ? Lafka_Deals::pricing( $product ) : array(
				'mode'  => 'fixed',
				'value' => 0.0,
			);
			$modes   = array(
				'fixed'    => __( 'Fixed price (the Regular / Sale price on the General tab)', 'lafka-plugin' ),
				'percent'  => __( 'Percent off the chosen items', 'lafka-plugin' ),
				'amount'   => __( 'Amount off the chosen items', 'lafka-plugin' ),
				'cheapest' => __( 'Cheapest chosen item free', 'lafka-plugin' ),
			);
			?>
			<p class="form-field">
				<label for="lafka_deal_mode"><?php esc_html_e( 'Pricing', 'lafka-plugin' ); ?></label>
				<select id="lafka_deal_mode" name="lafka_deal_mode" data-lafka-deal-mode>
					<?php foreach ( $modes as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $pricing['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-field" data-lafka-deal-value>
				<label for="lafka_deal_value"><?php esc_html_e( 'Discount', 'lafka-plugin' ); ?></label>
				<input type="number" min="0" step="0.01" class="short" id="lafka_deal_value" name="lafka_deal_value" value="<?php echo esc_attr( $pricing['value'] > 0 ? (string) $pricing['value'] : '' ); ?>" />
				<span class="description"><?php esc_html_e( 'A percentage for "percent off", an amount for "amount off". The items count at their own prices; premium-item differences do not apply. Optional items are charged on top.', 'lafka-plugin' ); ?></span>
			</p>
			<?php
		}

		/**
		 * Conditions: order type, hours, limits and coupons.
		 *
		 * @param WC_Product|null $product Product being edited.
		 * @return void
		 */
		private static function condition_fields( $product ): void {
			$conditions = $product instanceof WC_Product ? Lafka_Deals_Conditions::conditions( $product ) : Lafka_Deals_Conditions::conditions( 0 );
			?>
			<p class="form-field">
				<label for="lafka_deal_order_type"><?php esc_html_e( 'Order type', 'lafka-plugin' ); ?></label>
				<select id="lafka_deal_order_type" name="lafka_deal_order_type">
					<option value="" <?php selected( $conditions['order_type'], '' ); ?>><?php esc_html_e( 'Pickup and delivery', 'lafka-plugin' ); ?></option>
					<option value="pickup" <?php selected( $conditions['order_type'], 'pickup' ); ?>><?php esc_html_e( 'Pickup only', 'lafka-plugin' ); ?></option>
					<option value="delivery" <?php selected( $conditions['order_type'], 'delivery' ); ?>><?php esc_html_e( 'Delivery only', 'lafka-plugin' ); ?></option>
				</select>
			</p>
			<p class="form-field">
				<label for="lafka_deal_hours_from"><?php esc_html_e( 'Hours', 'lafka-plugin' ); ?></label>
				<input type="time" id="lafka_deal_hours_from" name="lafka_deal_hours_from" value="<?php echo esc_attr( $conditions['hours_from'] ); ?>" />
				<?php esc_html_e( 'to', 'lafka-plugin' ); ?>
				<input type="time" id="lafka_deal_hours_until" name="lafka_deal_hours_until" value="<?php echo esc_attr( $conditions['hours_until'] ); ?>" />
				<span class="description" style="display:block;"><?php esc_html_e( 'Optional. The time of day the deal runs, on the store\'s clock (Lafka → Order hours); an end before the start runs past midnight. Leave empty for all day.', 'lafka-plugin' ); ?></span>
			</p>
			<?php
			foreach ( array(
				'max_customer' => __( 'Most uses per customer', 'lafka-plugin' ),
				'max_total'    => __( 'Most uses in total', 'lafka-plugin' ),
			) as $key => $label ) :
				?>
				<p class="form-field">
					<label for="lafka_deal_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="number" min="0" step="1" class="short" id="lafka_deal_<?php echo esc_attr( $key ); ?>" name="lafka_deal_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $conditions[ $key ] > 0 ? (string) $conditions[ $key ] : '' ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'lafka-plugin' ); ?>" />
					<?php if ( 'max_customer' === $key ) : ?>
						<span class="description"><?php esc_html_e( 'Counted on paid orders, by account or billing email.', 'lafka-plugin' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
			<p class="form-field">
				<label for="lafka_deal_coupons"><?php esc_html_e( 'Coupons', 'lafka-plugin' ); ?></label>
				<select id="lafka_deal_coupons" name="lafka_deal_coupons">
					<option value="allow" <?php selected( $conditions['coupons'], 'allow' ); ?>><?php esc_html_e( 'Can be used with coupons', 'lafka-plugin' ); ?></option>
					<option value="block" <?php selected( $conditions['coupons'], 'block' ); ?>><?php esc_html_e( 'No coupons on an order with this deal', 'lafka-plugin' ); ?></option>
				</select>
			</p>
			<?php
		}

		/**
		 * When the deal runs: weekdays and an optional date range.
		 *
		 * @param WC_Product|null $product Product being edited.
		 * @return void
		 */
		private static function availability_fields( $product ): void {
			global $wp_locale;
			$when  = $product instanceof WC_Product ? Lafka_Deals::availability( $product ) : array(
				'days'  => array(),
				'from'  => '',
				'until' => '',
			);
			$start = (int) get_option( 'start_of_week', 1 );
			?>
			<p class="form-field lafka-deal-days">
				<label><?php esc_html_e( 'Runs on', 'lafka-plugin' ); ?></label>
				<?php
				for ( $i = 0; $i < 7; $i++ ) :
					$day = ( $start + $i ) % 7;
					?>
					<label style="float:none;width:auto;margin:0 1em 0 0;"><input type="checkbox" name="lafka_deal_days[]" value="<?php echo esc_attr( (string) $day ); ?>" <?php checked( in_array( $day, $when['days'], true ) ); ?> /> <?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $day ) ) ); ?></label>
				<?php endfor; ?>
				<span class="description" style="display:block;"><?php esc_html_e( 'Leave every day unticked to run it every day. Outside its days and dates the deal page says when it runs, and a deal left in a cart is removed.', 'lafka-plugin' ); ?></span>
			</p>
			<?php
			foreach ( array(
				'from'  => __( 'First day (optional)', 'lafka-plugin' ),
				'until' => __( 'Last day (optional)', 'lafka-plugin' ),
			) as $key => $label ) :
				?>
				<p class="form-field">
					<label for="lafka_deal_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="date" id="lafka_deal_<?php echo esc_attr( $key ); ?>" name="lafka_deal_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $when[ $key ] ); ?>" />
				</p>
				<?php
			endforeach;
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

			$mode = isset( $_POST['lafka_deal_mode'] ) ? sanitize_key( wp_unslash( $_POST['lafka_deal_mode'] ) ) : 'fixed';
			$product->update_meta_data( Lafka_Deals::MODE_META, in_array( $mode, Lafka_Deals::MODES, true ) ? $mode : 'fixed' );
			$product->update_meta_data( Lafka_Deals::VALUE_META, isset( $_POST['lafka_deal_value'] ) ? max( 0.0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['lafka_deal_value'] ) ) ) ) : 0.0 );

			$type = isset( $_POST['lafka_deal_order_type'] ) ? sanitize_key( wp_unslash( $_POST['lafka_deal_order_type'] ) ) : '';
			$product->update_meta_data( Lafka_Deals_Conditions::ORDER_TYPE_META, in_array( $type, array( 'pickup', 'delivery' ), true ) ? $type : '' );
			foreach ( array(
				'lafka_deal_hours_from'  => Lafka_Deals_Conditions::HOURS_FROM_META,
				'lafka_deal_hours_until' => Lafka_Deals_Conditions::HOURS_UNTIL_META,
			) as $field => $meta ) {
				$time = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
				$product->update_meta_data( $meta, preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '' );
			}
			foreach ( array(
				'lafka_deal_max_customer' => Lafka_Deals_Conditions::MAX_CUSTOMER_META,
				'lafka_deal_max_total'    => Lafka_Deals_Conditions::MAX_TOTAL_META,
			) as $field => $meta ) {
				$product->update_meta_data( $meta, isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0 );
			}
			$coupons = isset( $_POST['lafka_deal_coupons'] ) ? sanitize_key( wp_unslash( $_POST['lafka_deal_coupons'] ) ) : 'allow';
			$product->update_meta_data( Lafka_Deals_Conditions::COUPONS_META, 'block' === $coupons ? 'block' : 'allow' );

			$days = isset( $_POST['lafka_deal_days'] ) && is_array( $_POST['lafka_deal_days'] ) ? array_map( 'absint', wp_unslash( $_POST['lafka_deal_days'] ) ) : array();
			$days = array_values( array_unique( array_filter( $days, static fn( $d ) => $d <= 6 ) ) );
			$product->update_meta_data( Lafka_Deals::DAYS_META, 7 === count( $days ) ? array() : $days );
			foreach ( array(
				'lafka_deal_from'  => Lafka_Deals::FROM_META,
				'lafka_deal_until' => Lafka_Deals::UNTIL_META,
			) as $field => $meta ) {
				$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
				$product->update_meta_data( $meta, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '' );
			}
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
