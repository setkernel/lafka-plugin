<?php
/**
 * The deal builder on a Deal's product page: one step per slot — choose an
 * item, set its options — then add the whole deal in one request.
 *
 * Markup is theme-agnostic (`lafka-deal*` classes, styled with the theme's
 * tokens when present). It renders from WooCommerce's own
 * `woocommerce_lafka_deal_add_to_cart` action, which any theme's product
 * page fires through woocommerce_template_single_add_to_cart().
 *
 * Every price comes from the server: the quote and the add run the same
 * resolve() with the add-on engine's own validation and pricing.
 *
 * Endpoints (admin-ajax, nonce `lafka_deal`):
 *   lafka_deal_item  — a chosen item's options (attributes + add-ons) as HTML
 *   lafka_deal_quote — totals for the current choices
 *   lafka_deal_add   — validate and add the chosen items as one deal
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals_Builder' ) ) {

	/**
	 * Deal builder.
	 */
	final class Lafka_Deals_Builder {

		/**
		 * Selections injected into the add-on engine's validation while the
		 * builder adds an item (the request body is the builder's JSON, not a
		 * product form).
		 *
		 * @var array<string,mixed>|null
		 */
		private static $pending_post_data = null;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'woocommerce_' . Lafka_Deals::TYPE . '_add_to_cart', array( __CLASS__, 'render' ) );
			add_filter( 'lafka_addons_request_post_data', array( __CLASS__, 'inject_post_data' ) );
			foreach ( array( 'lafka_deal_item', 'lafka_deal_quote', 'lafka_deal_add' ) as $action ) {
				add_action( 'wp_ajax_' . $action, array( __CLASS__, str_replace( 'lafka_deal_', 'ajax_', $action ) ) );
				add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, str_replace( 'lafka_deal_', 'ajax_', $action ) ) );
			}
		}

		/**
		 * Feed the injected selections to the engine's validation.
		 *
		 * @param mixed $post_data Engine default (null).
		 * @return mixed
		 */
		public static function inject_post_data( $post_data ) {
			return is_array( self::$pending_post_data ) ? self::$pending_post_data : $post_data;
		}

		/**
		 * Render the builder.
		 *
		 * @return void
		 */
		public static function render(): void {
			global $product;
			if ( ! $product instanceof WC_Product || ! Lafka_Deals::is_deal( $product ) ) {
				return;
			}
			$slots = Lafka_Deals::get_slots( $product );
			if ( array() === $slots || ! $product->is_purchasable() ) {
				echo '<p class="lafka-deal__unavailable">' . esc_html__( 'This deal is not available right now.', 'lafka-plugin' ) . '</p>';
				return;
			}
			if ( class_exists( 'Lafka_Order_Hours' ) && Lafka_Order_Hours::is_add_to_cart_blocked() ) {
				Lafka_Order_Hours::echo_closed_store_message();
				return;
			}

			$rel = lafka_plugin_script_path( 'assets/js/lafka-deal-builder.min.js' );
			wp_enqueue_script( 'lafka-deal-builder', plugins_url( $rel, LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( $rel ), true );
			wp_enqueue_style( 'lafka-deal-builder', plugins_url( 'assets/css/lafka-deal-builder.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/lafka-deal-builder.css' ) );
			if ( isset( $GLOBALS['Lafka_Engine_Display'] ) && method_exists( $GLOBALS['Lafka_Engine_Display'], 'enqueue_scripts' ) ) {
				$GLOBALS['Lafka_Engine_Display']->enqueue_scripts();
			}

			$labels = array_column( $slots, 'label' );
			$config = array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lafka_deal' ),
				'dealId'  => $product->get_id(),
				'cartUrl' => wc_get_cart_url(),
				'i18n'    => array(
					/* translators: %s: slot label, e.g. "Pizza 2". */
					'choose' => __( 'Choose %s', 'lafka-plugin' ),
					/* translators: %s: deal total, e.g. "$21.50". */
					'add'    => __( 'Add to order · %s', 'lafka-plugin' ),
					'done'   => __( 'Done', 'lafka-plugin' ),
					'adding' => __( 'Adding…', 'lafka-plugin' ),
					'added'  => __( 'Added to your order', 'lafka-plugin' ),
					'failed' => __( 'Something went wrong. Please try again.', 'lafka-plugin' ),
				),
			);
			$saving = Lafka_Deals::max_saving( $product );
			?>
			<div class="lafka-deal" data-lafka-deal="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
				<p class="lafka-deal__intro">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of items, 2: list of slot labels. */
							_n( 'Choose %1$d item: %2$s. Extras are added to the price.', 'Choose %1$d items: %2$s. Extras are added to the price.', count( $slots ), 'lafka-plugin' ),
							count( $slots ),
							implode( ', ', $labels )
						)
					);
					if ( $saving > 0 ) {
						/* translators: %s: amount, e.g. "$8.97". */
						echo ' <strong>' . wp_kses_post( sprintf( __( 'Save up to %s.', 'lafka-plugin' ), wc_price( $saving ) ) ) . '</strong>';
					}
					?>
				</p>
				<ol class="lafka-deal__slots">
					<?php foreach ( $slots as $index => $slot ) : ?>
						<?php self::render_slot( $product, (int) $index, $slot ); ?>
					<?php endforeach; ?>
				</ol>
				<p class="lafka-deal__error" role="alert" data-lafka-deal-error hidden></p>
				<div class="lafka-deal__bar">
					<div class="lafka-deal__total" aria-live="polite">
						<span class="lafka-deal__price" data-lafka-deal-total><?php echo wp_kses_post( wc_price( (float) $product->get_price() ) ); ?></span>
						<span class="lafka-deal__note" data-lafka-deal-note></span>
					</div>
					<button type="button" class="lafka-deal__add button alt" data-lafka-deal-add disabled>
						<?php
						/* translators: %s: slot label, e.g. "Pizza 1". */
						echo esc_html( sprintf( __( 'Choose %s', 'lafka-plugin' ), $labels[0] ) );
						?>
					</button>
				</div>
			</div>
			<?php
		}

		/**
		 * One slot: the item list, and a place for the chosen item's options.
		 *
		 * @param WC_Product          $deal  Deal.
		 * @param int                 $index Slot index.
		 * @param array<string,mixed> $slot  Slot.
		 * @return void
		 */
		private static function render_slot( WC_Product $deal, int $index, array $slot ): void {
			$pool  = Lafka_Deals::pool( $slot );
			$floor = Lafka_Deals::pool_floor( $pool );
			$body  = 'lafka-deal-slot-' . $deal->get_id() . '-' . $index;
			?>
			<li class="lafka-deal-slot" data-lafka-deal-slot="<?php echo esc_attr( (string) $index ); ?>" data-label="<?php echo esc_attr( (string) $slot['label'] ); ?>" data-required="<?php echo $slot['required'] ? '1' : '0'; ?>">
				<button type="button" class="lafka-deal-slot__head" aria-expanded="<?php echo 0 === $index ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $body ); ?>">
					<span class="lafka-deal-slot__num" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<span class="lafka-deal-slot__label"><?php echo esc_html( (string) $slot['label'] ); ?><?php echo $slot['required'] ? '' : ' <span class="lafka-deal-slot__optional">' . esc_html__( '(optional)', 'lafka-plugin' ) . '</span>'; ?></span>
					<span class="lafka-deal-slot__choice" data-lafka-deal-choice><?php esc_html_e( 'Choose', 'lafka-plugin' ); ?></span>
				</button>
				<div class="lafka-deal-slot__body" id="<?php echo esc_attr( $body ); ?>" <?php echo 0 === $index ? '' : 'hidden'; ?>>
					<form class="lafka-deal-slot__form" data-lafka-deal-form>
						<?php if ( array() !== $slot['attributes'] ) : ?>
							<p class="lafka-deal-slot__locks">
								<?php
								foreach ( $slot['attributes'] as $taxonomy => $term_slug ) {
									// Taxonomy attributes have term names; product-level ones only the slug.
									$term  = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $term_slug, $taxonomy ) : false;
									$value = $term ? $term->name : ucwords( str_replace( '-', ' ', $term_slug ) );
									echo '<span class="lafka-deal-slot__lock">' . esc_html( ucfirst( wc_attribute_label( $taxonomy ) ) . ': ' . $value ) . '</span> ';
								}
								?>
							</p>
						<?php endif; ?>
						<fieldset class="lafka-deal-slot__pool">
							<legend class="screen-reader-text">
								<?php
								/* translators: %s: slot label. */
								echo esc_html( sprintf( __( 'Choose %s', 'lafka-plugin' ), (string) $slot['label'] ) );
								?>
							</legend>
							<?php if ( ! $slot['required'] ) : ?>
								<label class="lafka-deal-option lafka-deal-option--none">
									<input type="radio" name="lafka_deal_product" value="0" checked>
									<span class="lafka-deal-option__name"><?php esc_html_e( 'No thanks', 'lafka-plugin' ); ?></span>
								</label>
							<?php endif; ?>
							<?php foreach ( $pool as $id => $entry ) : ?>
								<?php
								$item    = $entry['product'];
								$premium = $slot['upcharge'] ? round( $entry['base'] - $floor, 2 ) : 0.0;
								?>
								<label class="lafka-deal-option">
									<input type="radio" name="lafka_deal_product" value="<?php echo esc_attr( (string) $id ); ?>">
									<?php echo wp_kses_post( $item->get_image( 'woocommerce_gallery_thumbnail', array( 'class' => 'lafka-deal-option__img' ) ) ); ?>
									<span class="lafka-deal-option__text">
										<span class="lafka-deal-option__name"><?php echo esc_html( $item->get_name() ); ?></span>
										<?php if ( '' !== $item->get_short_description() ) : ?>
											<span class="lafka-deal-option__desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $item->get_short_description() ), 14 ) ); ?></span>
										<?php endif; ?>
									</span>
									<?php if ( $premium > 0 ) : ?>
										<span class="lafka-deal-option__premium"><?php echo wp_kses_post( '+' . wc_price( $premium ) ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<div class="lafka-deal-slot__options" data-lafka-deal-options></div>
					</form>
				</div>
			</li>
			<?php
		}

		/**
		 * The deal a request names and its slots, or an error response.
		 *
		 * @param int $deal_id Deal id from the request.
		 * @return array{0: WC_Product, 1: array<int, array<string,mixed>>}
		 */
		private static function deal_or_fail( int $deal_id ): array {
			$deal = wc_get_product( $deal_id );
			if ( ! $deal || ! Lafka_Deals::is_deal( $deal ) || ! $deal->is_purchasable() ) {
				wp_send_json_error( array( 'message' => __( 'This deal is not available right now.', 'lafka-plugin' ) ), 404 );
			}
			return array( $deal, Lafka_Deals::get_slots( $deal ) );
		}

		/**
		 * The variation attributes a slot leaves to the customer, with the
		 * values offered (only values some matching variation has).
		 *
		 * @param WC_Product           $item  Variable product.
		 * @param array<string,string> $locks Locked attributes.
		 * @return array<string, array<string,string>> attribute key => value => label
		 */
		private static function open_attributes( WC_Product $item, array $locks ): array {
			$open = array();
			foreach ( $item->get_available_variations( 'objects' ) as $variation ) {
				if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() || ! Lafka_Deals::variation_matches( $variation, $locks ) ) {
					continue;
				}
				foreach ( $variation->get_attributes() as $name => $value ) {
					if ( isset( $locks[ $name ] ) ) {
						continue;
					}
					$values = '' === (string) $value ? (array) ( $item->get_variation_attributes()[ $name ] ?? array() ) : array( $value );
					foreach ( $values as $one ) {
						$term                           = taxonomy_exists( $name ) ? get_term_by( 'slug', $one, $name ) : false;
						$open[ $name ][ (string) $one ] = $term ? $term->name : (string) $one;
					}
				}
			}
			return $open;
		}

		/**
		 * AJAX: a chosen item's options.
		 *
		 * @return void
		 */
		public static function ajax_item(): void {
			check_ajax_referer( 'lafka_deal', 'nonce' );
			list( $deal, $slots ) = self::deal_or_fail( isset( $_POST['deal_id'] ) ? absint( $_POST['deal_id'] ) : 0 );
			$index                = isset( $_POST['slot'] ) ? absint( $_POST['slot'] ) : -1;
			$product_id           = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			if ( ! isset( $slots[ $index ] ) || ! isset( Lafka_Deals::pool( $slots[ $index ] )[ $product_id ] ) ) {
				wp_send_json_error( array( 'message' => __( 'That item is not part of this deal.', 'lafka-plugin' ) ), 400 );
			}
			$item = wc_get_product( $product_id );
			$slot = $slots[ $index ];

			ob_start();
			if ( $item->is_type( 'variable' ) ) {
				foreach ( self::open_attributes( $item, $slot['attributes'] ) as $name => $values ) {
					$field = 'attribute_' . sanitize_title( $name );
					echo '<p class="lafka-deal-slot__attr"><label>' . esc_html( wc_attribute_label( $name, $item ) ) . ' <select name="' . esc_attr( $field ) . '" required>';
					if ( count( $values ) > 1 ) {
						echo '<option value="">' . esc_html__( 'Choose…', 'lafka-plugin' ) . '</option>';
					}
					foreach ( $values as $value => $label ) {
						echo '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>';
					}
					echo '</select></label></p>';
				}
			}
			if ( isset( $GLOBALS['Lafka_Engine_Display'] ) ) {
				$GLOBALS['product'] = $item;
				$totals_priority    = has_action( 'lafka_product_addons_end', array( $GLOBALS['Lafka_Engine_Display'], 'totals' ) );
				if ( false !== $totals_priority ) {
					remove_action( 'lafka_product_addons_end', array( $GLOBALS['Lafka_Engine_Display'], 'totals' ), $totals_priority );
				}
				$GLOBALS['Lafka_Engine_Display']->display( $item->get_id() );
			}
			wp_send_json_success( array( 'html' => (string) ob_get_clean() ) );
		}

		/**
		 * The builder's choices: slot index => {product_id, attributes, addons}.
		 *
		 * @param string $json JSON of ids, attribute slugs and add-on values.
		 * @return array<int, array<string,mixed>>
		 */
		private static function parse_choices( string $json ): array {
			$raw     = json_decode( $json, true );
			$choices = array();
			foreach ( is_array( $raw ) ? $raw : array() as $index => $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}
				$choices[ absint( $index ) ] = array(
					'product_id' => absint( $choice['product_id'] ?? 0 ),
					'attributes' => array_map( 'sanitize_title', array_filter( (array) ( $choice['attributes'] ?? array() ), 'is_scalar' ) ),
					'addons'     => map_deep( (array) ( $choice['addons'] ?? array() ), 'sanitize_text_field' ),
				);
			}
			return $choices;
		}

		/**
		 * Resolve choices into deal lines, with prices from the same engine
		 * the cart uses.
		 *
		 * @param WC_Product                      $deal    Deal.
		 * @param array<int, array<string,mixed>> $slots   Slots.
		 * @param array<int, array<string,mixed>> $choices Choices.
		 * @return array{lines: array<int, array<string,mixed>>, missing: string[], errors: string[]}
		 */
		public static function resolve( WC_Product $deal, array $slots, array $choices ): array {
			$lines   = array();
			$missing = array();
			$errors  = array();
			foreach ( $slots as $index => $slot ) {
				$choice = $choices[ $index ] ?? array();
				$id     = (int) ( $choice['product_id'] ?? 0 );
				if ( 0 === $id ) {
					if ( $slot['required'] ) {
						$missing[] = (string) $slot['label'];
					}
					continue;
				}
				$pool = Lafka_Deals::pool( $slot );
				if ( ! isset( $pool[ $id ] ) ) {
					/* translators: %s: slot label. */
					$errors[] = sprintf( __( '%s: that item is not part of this deal.', 'lafka-plugin' ), $slot['label'] );
					continue;
				}
				$item         = $pool[ $id ]['product'];
				$variation_id = 0;
				$variation    = array();
				$priced       = $item;
				if ( $item->is_type( 'variable' ) ) {
					$wanted = array();
					foreach ( (array) $choice['attributes'] as $key => $value ) {
						$wanted[ preg_replace( '/^attribute_/', '', (string) $key ) ] = (string) $value;
					}
					$wanted = array_merge( $wanted, $slot['attributes'] );
					$match  = null;
					foreach ( $item->get_available_variations( 'objects' ) as $candidate ) {
						if ( $candidate instanceof WC_Product_Variation && $candidate->is_purchasable() && $candidate->is_in_stock() && Lafka_Deals::variation_matches( $candidate, $wanted ) ) {
							$match = $candidate;
							break;
						}
					}
					$open_missing = array_diff_key( self::open_attributes( $item, $slot['attributes'] ), array_filter( $wanted ) );
					if ( ! $match || array() !== $open_missing ) {
						$missing[] = (string) $slot['label'];
						continue;
					}
					$variation_id = $match->get_id();
					foreach ( $match->get_attributes() as $name => $value ) {
						$variation[ 'attribute_' . sanitize_title( $name ) ] = '' !== (string) $value ? (string) $value : (string) ( $wanted[ $name ] ?? '' );
					}
					$priced = $match;
				}

				// Add-ons: the engine's own validation and data, as for a normal add.
				$post_data = class_exists( 'Lafka_Engine_Store_API' ) ? Lafka_Engine_Store_API::map_selections_to_post_data( $item->get_id(), (array) $choice['addons'] ) : array();
				$addons    = array();
				if ( isset( $GLOBALS['Lafka_Engine_Cart'] ) ) {
					$engine = $GLOBALS['Lafka_Engine_Cart'];
					wc_clear_notices();
					if ( ! $engine->validate_add_cart_item( true, $item->get_id(), 1, $post_data ) ) {
						foreach ( wc_get_notices( 'error' ) as $notice ) {
							$errors[] = $slot['label'] . ': ' . wp_strip_all_tags( (string) ( $notice['notice'] ?? '' ) );
						}
						wc_clear_notices();
						continue;
					}
					try {
						$addons = (array) ( $engine->add_cart_item_data( array(), $item->get_id(), $post_data )['addons'] ?? array() );
					} catch ( Exception $e ) {
						$errors[] = $slot['label'] . ': ' . $e->getMessage();
						continue;
					}
					// Per-size prices exactly as the cart applies them.
					$probe  = $engine->add_cart_item(
						array(
							'data'         => clone $priced,
							'addons'       => $addons,
							'product_id'   => $item->get_id(),
							'variation_id' => $variation_id,
							'variation'    => $variation,
						)
					);
					$addons = (array) ( $probe['addons'] ?? $addons );
				}

				$base     = (float) $priced->get_price();
				$floor    = Lafka_Deals::pool_floor( $pool );
				$upcharge = $slot['upcharge'] ? max( 0.0, round( $base - $floor, 2 ) ) : 0.0;
				if ( ! $slot['required'] ) {
					// An optional add-on item is not part of the deal price: it
					// costs its own price, on top.
					$upcharge = $base;
				}
				$extras    = 0.0;
				$summaries = array();
				foreach ( $addons as $addon ) {
					$extras     += (float) ( $addon['price'] ?? 0 );
					$summaries[] = (string) ( $addon['value'] ?? '' );
				}
				foreach ( array_reverse( $variation, true ) as $key => $value ) {
					$taxonomy = preg_replace( '/^attribute_/', '', (string) $key );
					if ( isset( $slot['attributes'][ $taxonomy ] ) ) {
						continue; // Locked, already shown on the slot.
					}
					$term = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : false;
					array_unshift( $summaries, $term ? $term->name : $value );
				}

				$lines[ $index ] = array(
					'product_id'   => $item->get_id(),
					'variation_id' => $variation_id,
					'variation'    => $variation,
					'addons'       => $addons,
					'post_data'    => $post_data,
					'base'         => $base,
					'reference'    => $base - $upcharge,
					'upcharge'     => $upcharge,
					'extras'       => round( $extras, 2 ),
					'summary'      => trim( $item->get_name() . ( array() !== array_filter( $summaries ) ? ' · ' . implode( ', ', array_filter( $summaries ) ) : '' ) ),
				);
			}
			return array(
				'lines'   => $lines,
				'missing' => $missing,
				'errors'  => $errors,
			);
		}

		/**
		 * A price as plain text ("$24.50") for the builder's live total, which
		 * the script writes with textContent.
		 *
		 * @param float $amount Amount.
		 * @return string
		 */
		private static function plain_price( float $amount ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * Totals for resolved lines.
		 *
		 * @param WC_Product                      $deal  Deal.
		 * @param array<int, array<string,mixed>> $lines Lines.
		 * @return array{total: float, upcharges: float, extras: float, saving: float}
		 */
		private static function totals( WC_Product $deal, array $lines ): array {
			$upcharges = (float) array_sum( array_column( $lines, 'upcharge' ) );
			$extras    = (float) array_sum( array_column( $lines, 'extras' ) );
			$total     = (float) $deal->get_price() + $upcharges + $extras;
			$a_la      = (float) array_sum( array_column( $lines, 'base' ) ) + $extras;
			return array(
				'total'     => round( $total, 2 ),
				'upcharges' => round( $upcharges, 2 ),
				'extras'    => round( $extras, 2 ),
				'saving'    => max( 0.0, round( $a_la - $total, 2 ) ),
			);
		}

		/**
		 * AJAX: the quote for the current choices.
		 *
		 * @return void
		 */
		public static function ajax_quote(): void {
			check_ajax_referer( 'lafka_deal', 'nonce' );
			list( $deal, $slots ) = self::deal_or_fail( isset( $_POST['deal_id'] ) ? absint( $_POST['deal_id'] ) : 0 );
			$choices              = self::parse_choices( isset( $_POST['choices'] ) ? sanitize_textarea_field( wp_unslash( $_POST['choices'] ) ) : '' );
			$resolved             = self::resolve( $deal, $slots, $choices );
			$totals               = self::totals( $deal, $resolved['lines'] );
			$note                 = array();
			if ( $totals['extras'] > 0 || $totals['upcharges'] > 0 ) {
				/* translators: %s: amount of extras. */
				$note[] = sprintf( __( 'incl. %s extras', 'lafka-plugin' ), self::plain_price( $totals['extras'] + $totals['upcharges'] ) );
			}
			if ( $totals['saving'] > 0 && array() === $resolved['missing'] ) {
				/* translators: %s: amount saved. */
				$note[] = sprintf( __( 'you save %s', 'lafka-plugin' ), self::plain_price( $totals['saving'] ) );
			}
			wp_send_json_success(
				array(
					'total'     => self::plain_price( $totals['total'] ),
					'note'      => implode( ' · ', $note ),
					'missing'   => $resolved['missing'],
					'errors'    => $resolved['errors'],
					'summaries' => array_map(
						static function ( $line ) {
							return $line['summary'];
						},
						$resolved['lines']
					),
				)
			);
		}

		/**
		 * AJAX: add the deal.
		 *
		 * @return void
		 */
		public static function ajax_add(): void {
			check_ajax_referer( 'lafka_deal', 'nonce' );
			list( $deal, $slots ) = self::deal_or_fail( isset( $_POST['deal_id'] ) ? absint( $_POST['deal_id'] ) : 0 );
			$choices              = self::parse_choices( isset( $_POST['choices'] ) ? sanitize_textarea_field( wp_unslash( $_POST['choices'] ) ) : '' );
			if ( class_exists( 'Lafka_Order_Hours' ) && Lafka_Order_Hours::is_add_to_cart_blocked() ) {
				wp_send_json_error( array( 'message' => __( 'We are closed for orders right now.', 'lafka-plugin' ) ), 409 );
			}
			$resolved = self::resolve( $deal, $slots, $choices );
			if ( array() !== $resolved['errors'] || array() !== $resolved['missing'] || array() === $resolved['lines'] ) {
				$message = array() !== $resolved['errors']
					? implode( ' ', $resolved['errors'] )
					/* translators: %s: list of slot labels still to choose. */
					: sprintf( __( 'Please choose: %s.', 'lafka-plugin' ), implode( ', ', $resolved['missing'] ) );
				wp_send_json_error( array( 'message' => $message ), 400 );
			}

			$group = wp_generate_uuid4();
			$added = array();
			foreach ( $resolved['lines'] as $index => $line ) {
				self::$pending_post_data = $line['post_data'];
				$key                     = WC()->cart->add_to_cart(
					$line['product_id'],
					1,
					$line['variation_id'],
					$line['variation'],
					array(
						'addons'              => $line['addons'],
						Lafka_Deals::CART_KEY => array(
							'deal_id'    => $deal->get_id(),
							'deal_name'  => $deal->get_name(),
							'group'      => $group,
							'slot'       => (int) $index,
							'slot_label' => (string) $slots[ $index ]['label'],
							'slots'      => count( $resolved['lines'] ),
							'reference'  => (float) $line['reference'],
							'upcharge'   => (float) $line['upcharge'],
						),
					)
				);
				self::$pending_post_data = null;
				if ( ! $key ) {
					// All or nothing: take back what this deal already added.
					foreach ( $added as $done ) {
						WC()->cart->remove_cart_item( $done );
					}
					$notices = wc_get_notices( 'error' );
					wc_clear_notices();
					wp_send_json_error( array( 'message' => wp_strip_all_tags( (string) ( $notices[0]['notice'] ?? __( 'Something went wrong. Please try again.', 'lafka-plugin' ) ) ) ), 400 );
				}
				$added[] = $key;
			}
			wc_clear_notices();

			WC()->cart->calculate_totals();
			ob_start();
			woocommerce_mini_cart();
			$mini = (string) ob_get_clean();
			wp_send_json_success(
				array(
					'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array( 'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini . '</div>' ) ),
					'cart_hash' => WC()->cart->get_cart_hash(),
					'count'     => WC()->cart->get_cart_contents_count(),
				)
			);
		}
	}
}
