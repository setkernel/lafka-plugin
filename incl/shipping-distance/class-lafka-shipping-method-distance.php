<?php
/**
 * Lafka_Shipping_Method_Distance: the `lafka_distance` WooCommerce shipping
 * method, a delivery fee by distance from the restaurant (or the chosen
 * branch). Added to a shipping zone like any WooCommerce method
 * (WooCommerce > Settings > Shipping > zone > Add shipping method), so zones,
 * taxes, the cart, both checkouts and the Store API treat it as a normal rate.
 *
 * Instance settings: title, tax status, distance unit (km / mi), how distance
 * is measured (straight line x road factor, or driving distance), the road
 * factor, the maximum distance, free delivery over the store's threshold, the
 * distance bands (up to N -> fee, plus an optional amount per km / mile), and
 * whether the distance is shown. A minimum order for delivery is not set here:
 * it is the Promotions delivery minimum (lafka_delivery_minimum()), the one home
 * every notice and the checkout read.
 *
 * A fee is only ever worked out from a distance Lafka could measure: with no
 * origin, no precise destination or no route the method offers no rate, says
 * why on the cart / checkout, and logs the reason on the `shipping` channel.
 *
 * Rate meta: `Distance` ("4.2 km", shown under the rate label and on the
 * order), `_lafka_distance_km`, `_lafka_distance_mode`, `_lafka_distance_source`
 * ('pin' or 'geocode') and, when free delivery applied, `lafka_free_delivery`.
 *
 * @package Lafka\Plugin\ShippingDistance
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Shipping_Method_Distance' ) && class_exists( 'WC_Shipping_Method' ) ) {

	/**
	 * Distance-priced delivery.
	 */
	class Lafka_Shipping_Method_Distance extends WC_Shipping_Method {

		/** Method id. */
		const ID = 'lafka_distance';

		/**
		 * Constructor.
		 *
		 * @param int $instance_id Shipping zone instance id.
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = self::ID;
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Delivery by distance', 'lafka-plugin' );
			$this->method_description = __( 'A delivery fee by distance from the restaurant, no Google account needed.', 'lafka-plugin' );
			$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
			$this->init_form_fields();
			$this->init_settings();
			$this->title = $this->get_option( 'title', __( 'Delivery', 'lafka-plugin' ) );

			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * The settings form.
		 *
		 * @return void
		 */
		public function init_form_fields() {
			$threshold = function_exists( 'lafka_get_free_delivery_threshold' ) ? lafka_get_free_delivery_threshold() : 0.0;
			if ( $threshold > 0 ) {
				/* translators: %s: formatted free-delivery threshold. */
				$free_note = sprintf( __( 'The store\'s free-delivery threshold is currently %s (WooCommerce > Settings > Restaurant > Promotions).', 'lafka-plugin' ), wp_strip_all_tags( wc_price( $threshold ) ) );
			} else {
				$free_note = __( 'No free-delivery threshold is set yet (WooCommerce > Settings > Restaurant > Promotions), so nothing is free.', 'lafka-plugin' );
			}

			$this->instance_form_fields = array(
				'title'         => array(
					'title'       => __( 'Title', 'lafka-plugin' ),
					'type'        => 'text',
					'description' => __( 'What the customer sees.', 'lafka-plugin' ),
					'default'     => __( 'Delivery', 'lafka-plugin' ),
					'desc_tip'    => true,
				),
				'tax_status'    => array(
					'title'   => __( 'Tax status', 'lafka-plugin' ),
					'type'    => 'select',
					'class'   => 'wc-enhanced-select',
					'default' => 'taxable',
					'options' => array(
						'taxable' => __( 'Taxable', 'lafka-plugin' ),
						'none'    => _x( 'None', 'Tax status', 'lafka-plugin' ),
					),
				),
				'unit'          => array(
					'title'   => __( 'Distance unit', 'lafka-plugin' ),
					'type'    => 'select',
					'class'   => 'wc-enhanced-select',
					'default' => 'km',
					'options' => array(
						'km' => __( 'Kilometres', 'lafka-plugin' ),
						'mi' => __( 'Miles', 'lafka-plugin' ),
					),
				),
				'mode'          => array(
					'title'       => __( 'How distance is measured', 'lafka-plugin' ),
					'type'        => 'select',
					'class'       => 'wc-enhanced-select',
					'default'     => 'straight',
					'options'     => array(
						'straight' => __( 'Straight line x road factor (no Google needed)', 'lafka-plugin' ),
						'driving'  => __( 'Driving distance (Google Maps key or an OSRM server)', 'lafka-plugin' ),
					),
					'description' => __( 'Driving distance asks Google\'s Routes API (when the Google Maps key is set under Lafka Shipping Settings) or an OSRM server (the lafka_distance_osrm_endpoint filter). If neither is available the method offers no rate.', 'lafka-plugin' ),
					'desc_tip'    => true,
				),
				'road_factor'   => array(
					'title'             => __( 'Road factor', 'lafka-plugin' ),
					'type'              => 'number',
					'default'           => '1.3',
					'custom_attributes' => array(
						'step' => '0.05',
						'min'  => '1',
					),
					'description'       => __( 'Straight-line distance is multiplied by this to approximate the road. 1.3 suits most towns.', 'lafka-plugin' ),
					'desc_tip'          => true,
				),
				'max_distance'  => array(
					'title'             => __( 'Maximum distance', 'lafka-plugin' ),
					'type'              => 'number',
					'default'           => '',
					'custom_attributes' => array(
						'step' => '0.1',
						'min'  => '0',
					),
					'description'       => __( 'Beyond this there is no delivery rate. Leave empty for no limit (the last band then has no upper limit).', 'lafka-plugin' ),
					'desc_tip'          => true,
				),
				'free_over'     => array(
					'title'       => __( 'Free delivery', 'lafka-plugin' ),
					'type'        => 'checkbox',
					'label'       => __( 'Free over the store\'s free-delivery threshold', 'lafka-plugin' ),
					'default'     => 'yes',
					'description' => $free_note,
				),
				'show_distance' => array(
					'title'   => __( 'Show distance', 'lafka-plugin' ),
					'type'    => 'checkbox',
					'label'   => __( 'Show the distance under the rate and on the order ("Delivery · 4.2 km")', 'lafka-plugin' ),
					'default' => 'yes',
				),
				'bands'         => array(
					'title'   => __( 'Distance bands', 'lafka-plugin' ),
					'type'    => 'lafka_bands',
					'default' => self::default_bands(),
				),
			);
		}

		/**
		 * Starting bands for a new instance (change them in the zone).
		 *
		 * @return array<int,array<string,string>>
		 */
		public static function default_bands(): array {
			/**
			 * Filter the distance bands a new `lafka_distance` instance starts with.
			 *
			 * @since 10.4.0
			 * @param array $bands Rows with up_to, fee and per_unit.
			 */
			return (array) apply_filters(
				'lafka_distance_default_bands',
				array(
					array(
						'up_to'    => '3',
						'fee'      => '3',
						'per_unit' => '',
					),
					array(
						'up_to'    => '6',
						'fee'      => '5',
						'per_unit' => '',
					),
					array(
						'up_to'    => '10',
						'fee'      => '8',
						'per_unit' => '',
					),
				)
			);
		}

		/**
		 * The bands table. A few blank rows follow the saved ones; fill one
		 * and save to add a band (an emptied row is removed on save).
		 *
		 * @param string $key  Field key.
		 * @param array  $data Field definition.
		 * @return string
		 */
		public function generate_lafka_bands_html( $key, $data ) {
			$field = $this->get_field_key( $key );
			$rows  = $this->get_option( $key, $data['default'] ?? array() );
			$rows  = is_array( $rows ) ? array_values( $rows ) : array();
			for ( $i = 0; $i < 3; $i++ ) {
				$rows[] = array();
			}
			$unit = 'mi' === $this->get_option( 'unit', 'km' ) ? 'mi' : 'km';

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><label><?php echo esc_html( $data['title'] ); ?></label></th>
				<td class="forminp">
					<table class="widefat lafka-distance-bands" style="max-width:640px">
						<thead>
							<tr>
								<th><?php echo esc_html( sprintf( /* translators: %s: km or mi. */ __( 'Up to (%s)', 'lafka-plugin' ), $unit ) ); ?></th>
								<th><?php esc_html_e( 'Fee', 'lafka-plugin' ); ?></th>
								<th><?php echo esc_html( sprintf( /* translators: %s: km or mi. */ __( 'Plus per %s', 'lafka-plugin' ), $unit ) ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $rows as $i => $row ) : ?>
							<tr>
								<?php foreach ( array( 'up_to', 'fee', 'per_unit' ) as $col ) : ?>
									<td><input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr( $field . '[' . $i . '][' . $col . ']' ); ?>" value="<?php echo esc_attr( (string) ( $row[ $col ] ?? '' ) ); ?>" /></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'The first band the distance fits in sets the fee. Fee plus "Plus per" times the distance gives the price (leave "Plus per" empty for a flat fee). A band with no "Up to" covers everything further, up to the maximum distance. A minimum order for delivery is the Promotions delivery minimum, not set here. To add a band fill a blank row and save; empty a row to remove it.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<?php
			return (string) ob_get_clean();
		}

		/**
		 * Clean the posted bands: numbers only, empty rows dropped, sorted by
		 * "Up to", at most one open-ended band and only as the last.
		 *
		 * @param string $key   Field key.
		 * @param mixed  $value Posted rows.
		 * @return array<int,array<string,string>>
		 */
		public function validate_lafka_bands_field( $key, $value ) {
			unset( $key );
			$clean = array();
			foreach ( is_array( $value ) ? $value : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$item = array();
				foreach ( array( 'up_to', 'fee', 'per_unit' ) as $col ) {
					$raw          = isset( $row[ $col ] ) ? trim( (string) wc_clean( wp_unslash( $row[ $col ] ) ) ) : '';
					$item[ $col ] = '' !== $raw && is_numeric( wc_format_decimal( $raw ) ) ? (string) max( 0, (float) wc_format_decimal( $raw ) ) : '';
				}
				if ( '' === $item['up_to'] && '' === $item['fee'] && '' === $item['per_unit'] ) {
					continue;
				}
				$clean[] = $item;
			}
			usort(
				$clean,
				static function ( $a, $b ) {
					$x = '' === $a['up_to'] ? INF : (float) $a['up_to'];
					$y = '' === $b['up_to'] ? INF : (float) $b['up_to'];
					return $x <=> $y;
				}
			);
			$out = array();
			foreach ( $clean as $item ) {
				$out[] = $item;
				if ( '' === $item['up_to'] ) {
					break;
				}
			}

			return $out;
		}

		/**
		 * The saved bands, sorted, as numbers.
		 *
		 * @return array<int,array{up_to:?float,fee:float,per_unit:float}>
		 */
		public function bands(): array {
			$saved = $this->get_option( 'bands', self::default_bands() );
			$out   = array();
			foreach ( is_array( $saved ) ? $saved : array() as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$out[] = array(
					'up_to'    => isset( $row['up_to'] ) && '' !== $row['up_to'] ? (float) $row['up_to'] : null,
					'fee'      => (float) ( $row['fee'] ?? 0 ),
					'per_unit' => (float) ( $row['per_unit'] ?? 0 ),
				);
			}
			usort(
				$out,
				static function ( $a, $b ) {
					return ( $a['up_to'] ?? INF ) <=> ( $b['up_to'] ?? INF );
				}
			);

			return $out;
		}

		/**
		 * Work out the delivery rate for a package.
		 *
		 * @param array $package WooCommerce shipping package.
		 * @return void
		 */
		public function calculate_shipping( $package = array() ) {
			Lafka_Distance_Shipping::set_reason( null );
			$package = is_array( $package ) ? $package : array();

			// Never geocode a half-typed address: the guard would withhold the rate anyway.
			$destination = (array) ( $package['destination'] ?? array() );
			if ( class_exists( 'Lafka_Delivery_Quote_Guard' ) && Lafka_Delivery_Quote_Guard::is_enabled() && ! Lafka_Delivery_Quote_Guard::destination_is_complete( $destination ) ) {
				return;
			}
			if ( '' === trim( (string) ( $destination['address_1'] ?? $destination['address'] ?? '' ) ) ) {
				return;
			}

			$unit   = 'mi' === $this->get_option( 'unit', 'km' ) ? 'mi' : 'km';
			$mode   = 'driving' === $this->get_option( 'mode', 'straight' ) ? 'driving' : 'straight';
			$origin = Lafka_Distance_Resolver::origin();
			if ( null === $origin ) {
				$this->decline( 'no_origin', 'The store has no location point, so the distance cannot be measured. Set it under Lafka Shipping Settings > Advanced.', 'unavailable' );
				return;
			}
			$target = Lafka_Distance_Resolver::destination( $package );
			if ( is_wp_error( $target ) ) {
				$this->decline( $target->get_error_code(), $target->get_error_message(), 'address' );
				return;
			}
			$km = Lafka_Distance_Resolver::distance_km( $origin, $target['point'], $mode, (float) $this->get_option( 'road_factor', '1.3' ) );
			if ( is_wp_error( $km ) ) {
				$this->decline( $km->get_error_code(), $km->get_error_message(), 'unavailable' );
				return;
			}

			$per_km   = 'mi' === $unit ? Lafka_Distance_Resolver::KM_PER_MILE : 1.0;
			$distance = round( $km / $per_km, 1 );
			$max      = (float) $this->get_option( 'max_distance', '' );

			$band = null;
			if ( $max <= 0 || $distance <= $max ) {
				foreach ( $this->bands() as $candidate ) {
					if ( null === $candidate['up_to'] || $distance <= $candidate['up_to'] ) {
						$band = $candidate;
						break;
					}
				}
			}
			if ( null === $band ) {
				$this->decline( 'beyond_range', sprintf( 'Delivery distance %s %s is beyond the range.', $distance, $unit ), 'beyond', 'notice', array( 'distance' => $distance ) );
				return;
			}

			$contents = (float) ( $package['contents_cost'] ?? 0 );

			$cost = round( $band['fee'] + $band['per_unit'] * $distance, wc_get_price_decimals() );
			$free = false;
			if ( 'yes' === $this->get_option( 'free_over', 'yes' ) && function_exists( 'lafka_free_delivery_eligible' ) && lafka_free_delivery_eligible( $contents ) ) {
				$cost = 0.0;
				$free = true;
			}

			$label = sprintf( '%s %s', rtrim( rtrim( number_format( $distance, 1, '.', '' ), '0' ), '.' ), $unit );
			$meta  = array(
				'_lafka_distance_km'     => round( $km, 3 ),
				'_lafka_distance_mode'   => $mode,
				'_lafka_distance_source' => $target['source'],
			);
			if ( 'yes' === $this->get_option( 'show_distance', 'yes' ) ) {
				$meta['Distance'] = $label;
			}
			if ( $free ) {
				$meta['lafka_free_delivery'] = 'yes';
			}

			$this->add_rate(
				array(
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => $cost,
					'package'   => $package,
					'meta_data' => $meta,
				)
			);
		}

		/**
		 * Offer no rate: log why and remember what to tell the customer.
		 *
		 * @param string $code    Reason code.
		 * @param string $detail  Log message.
		 * @param string $kind    'address', 'unavailable' or 'beyond'.
		 * @param string $level   Log level.
		 * @param array  $context Extra log context.
		 * @return void
		 */
		private function decline( string $code, string $detail, string $kind, string $level = 'warning', array $context = array() ): void {
			Lafka_Log::log(
				$level,
				'shipping',
				'Delivery by distance: no rate. ' . $detail,
				array_merge(
					$context,
					array(
						'code'        => 'distance_' . $code,
						'instance_id' => $this->instance_id,
					)
				)
			);
			Lafka_Distance_Shipping::set_reason(
				array(
					'kind' => $kind,
					'max'  => (float) $this->get_option( 'max_distance', '' ),
					'unit' => 'mi' === $this->get_option( 'unit', 'km' ) ? 'mi' : 'km',
				)
			);
		}
	}
}
