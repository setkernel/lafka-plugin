<?php
/**
 * WP-CLI: provision a deterministic minimal demo restaurant (NX1-09a).
 *
 *   wp lafka seed-demo            # create/update the demo store (idempotent)
 *   wp lafka seed-demo --reset    # delete exactly what the seeder created
 *
 * WHY: Playwright e2e was local-only because CI had no demo content and the
 * theme's legacy store/demo packs are unusable. This command seeds a browsable,
 * orderable store from a single deterministic fixture (incl/cli/data/seed-demo.php)
 * so e2e-in-CI (NX1-09b) and every preset/visual QA job run against one known
 * store. It is the kernel the NX3 demo packs grow from.
 *
 * The seeded store:
 *   - 12 WC simple/variable products across 4 neutral categories,
 *   - tiny placeholder images generated at seed time via GD (no bundled binaries,
 *     no remote fetches),
 *   - 2 addon groups exercising the flat-per-option + flat-group pricing
 *     strategies, assigned to the pizza category,
 *   - fake-but-schema-valid business info written through the same lafka_business_*
 *     options the WooCommerce → Restaurant tab writes,
 *   - an always-open 7-day order-hours schedule,
 *   - one branch term + one shipping-area polygon around the fake centre,
 *   - WC pages (shop/cart/checkout/my-account) + a /menu/ page,
 *   - order_hours + shipping_areas feature flags enabled so the gates fire.
 *
 * Idempotent: everything is matched by slug/SKU/title and updated in place, so a
 * re-run writes no duplicates. --reset deletes exactly the objects this seeder
 * created, tracked by id in the `lafka_seed_demo_manifest` option.
 *
 * The pure helpers (fixtures / manifest / decision / polygon encoding) carry no
 * WordPress dependency so they are unit-testable via Brain Monkey without booting
 * WP (see tests/Unit/SeedDemoFixtureTest.php). Only the provisioning methods,
 * invoked exclusively under WP-CLI, touch a live install. The class is always
 * defined; only the command registration self-gates on WP_CLI.
 *
 * @package Lafka\Plugin\CLI
 * @since   9.37.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_CLI_Seed_Demo' ) ) {

	/**
	 * Seeds and tears down the deterministic demo restaurant.
	 */
	class Lafka_CLI_Seed_Demo {

		/** Option storing the ids of everything the seeder created. */
		const MANIFEST_OPTION = 'lafka_seed_demo_manifest';

		/** Manifest envelope version. Bump only on a breaking shape change. */
		const MANIFEST_VERSION = 1;

		/** Taxonomy holding branch terms. */
		const BRANCH_TAXONOMY = 'lafka_branch_location';

		/** CPT holding delivery-zone polygons. */
		const AREA_POST_TYPE = 'lafka_shipping_areas';

		/** CPT holding global add-on groups. */
		const ADDON_POST_TYPE = 'lafka_glb_addon';

		/**
		 * Manifest id buckets, one per kind of object the seeder owns.
		 *
		 * @return array<int,string>
		 */
		private static function manifest_buckets(): array {
			return array( 'categories', 'products', 'attachments', 'addon_groups', 'branches', 'areas', 'pages' );
		}

		// ─── Pure helpers (unit-tested, no live-WP requirement) ──────────────

		/**
		 * The deterministic fixture data. Cached for the request.
		 *
		 * @return array<string,mixed>
		 */
		public static function fixtures(): array {
			static $cache = null;
			if ( null === $cache ) {
				$cache = require __DIR__ . '/data/seed-demo.php';
			}
			return is_array( $cache ) ? $cache : array();
		}

		/**
		 * A fresh, empty manifest with every id bucket present.
		 *
		 * @return array<string,mixed>
		 */
		public static function empty_manifest(): array {
			$ids = array();
			foreach ( self::manifest_buckets() as $bucket ) {
				$ids[ $bucket ] = array();
			}
			return array(
				'version'   => self::MANIFEST_VERSION,
				'seeded_at' => '',
				'ids'       => $ids,
			);
		}

		/**
		 * Record an object id under a bucket, keyed by its stable slug/title.
		 * Pure: returns the updated manifest, never mutates the argument.
		 *
		 * @param array<string,mixed> $manifest Manifest.
		 * @param string              $bucket   Id bucket (e.g. 'products').
		 * @param string              $key      Stable key (slug/title).
		 * @param int                 $id       Object id.
		 * @return array<string,mixed>
		 */
		public static function record( array $manifest, string $bucket, string $key, int $id ): array {
			if ( ! isset( $manifest['ids'] ) || ! is_array( $manifest['ids'] ) ) {
				$manifest['ids'] = array();
			}
			if ( ! isset( $manifest['ids'][ $bucket ] ) || ! is_array( $manifest['ids'][ $bucket ] ) ) {
				$manifest['ids'][ $bucket ] = array();
			}
			$manifest['ids'][ $bucket ][ $key ] = $id;
			return $manifest;
		}

		/**
		 * Read a recorded id (0 when absent).
		 *
		 * @param array<string,mixed> $manifest Manifest.
		 * @param string              $bucket   Id bucket.
		 * @param string              $key      Stable key.
		 * @return int
		 */
		public static function recorded_id( array $manifest, string $bucket, string $key ): int {
			if ( ! isset( $manifest['ids'][ $bucket ][ $key ] ) ) {
				return 0;
			}
			return (int) $manifest['ids'][ $bucket ][ $key ];
		}

		/**
		 * Load the manifest from the option store, normalising missing/corrupt
		 * data to a fresh empty manifest with every bucket present.
		 *
		 * @return array<string,mixed>
		 */
		public static function load_manifest(): array {
			$raw = get_option( self::MANIFEST_OPTION, array() );
			if ( ! is_array( $raw ) || ! isset( $raw['ids'] ) || ! is_array( $raw['ids'] ) ) {
				return self::empty_manifest();
			}
			$manifest = self::empty_manifest();
			foreach ( self::manifest_buckets() as $bucket ) {
				if ( isset( $raw['ids'][ $bucket ] ) && is_array( $raw['ids'][ $bucket ] ) ) {
					$manifest['ids'][ $bucket ] = $raw['ids'][ $bucket ];
				}
			}
			if ( isset( $raw['seeded_at'] ) ) {
				$manifest['seeded_at'] = (string) $raw['seeded_at'];
			}
			return $manifest;
		}

		/**
		 * Persist the manifest.
		 *
		 * @param array<string,mixed> $manifest Manifest.
		 * @return void
		 */
		public static function save_manifest( array $manifest ): void {
			update_option( self::MANIFEST_OPTION, $manifest );
		}

		/**
		 * Idempotency decision: an object already present (id > 0) is updated in
		 * place; otherwise it is created.
		 *
		 * @param int $existing_id Existing object id (0 when none).
		 * @return string 'create'|'update'
		 */
		public static function decide_action( int $existing_id ): string {
			return $existing_id > 0 ? 'update' : 'create';
		}

		/**
		 * A closed square ring (4 corners) around a centre point.
		 *
		 * @param float $lat  Centre latitude.
		 * @param float $lng  Centre longitude.
		 * @param float $half Half-side in degrees.
		 * @return array<int,array{0:float,1:float}> List of [lat, lng] pairs.
		 */
		public static function square_polygon( float $lat, float $lng, float $half ): array {
			return array(
				array( $lat + $half, $lng - $half ),
				array( $lat + $half, $lng + $half ),
				array( $lat - $half, $lng + $half ),
				array( $lat - $half, $lng - $half ),
			);
		}

		/**
		 * Encode a list of [lat, lng] pairs into Google's "Encoded Polyline
		 * Algorithm Format" — the exact string the frontend geo-fence and
		 * Lafka_Shipping_Areas::decode_polygon_coordinates() consume. Pure mirror
		 * of that decoder, so the seeded polygon round-trips through the real
		 * server-side geo-fence.
		 *
		 * @param array<int,array{0:float,1:float}> $points [lat, lng] pairs.
		 * @return string
		 */
		public static function encode_polygon_coordinates( array $points ): string {
			$out       = '';
			$prev_lat  = 0;
			$prev_lng  = 0;
			foreach ( $points as $point ) {
				$lat       = (int) round( (float) $point[0] * 100000 );
				$lng       = (int) round( (float) $point[1] * 100000 );
				$out      .= self::encode_signed( $lat - $prev_lat );
				$out      .= self::encode_signed( $lng - $prev_lng );
				$prev_lat  = $lat;
				$prev_lng  = $lng;
			}
			return $out;
		}

		/**
		 * Encode one signed integer delta per the polyline algorithm.
		 *
		 * @param int $value Signed delta (already scaled by 1e5).
		 * @return string
		 */
		private static function encode_signed( int $value ): string {
			$value  = $value < 0 ? ~( $value << 1 ) : ( $value << 1 );
			$chunks = '';
			while ( $value >= 0x20 ) {
				$chunks .= chr( ( 0x20 | ( $value & 0x1f ) ) + 63 );
				$value >>= 5;
			}
			$chunks .= chr( $value + 63 );
			return $chunks;
		}

		// ─── WP-CLI command ──────────────────────────────────────────────────

		/**
		 * Seed (or reset) the deterministic demo restaurant.
		 *
		 * ## OPTIONS
		 *
		 * [--reset]
		 * : Delete exactly what a previous seed created (tracked in the
		 *   lafka_seed_demo_manifest option) instead of seeding.
		 *
		 * ## EXAMPLES
		 *
		 *     wp lafka seed-demo
		 *     wp lafka seed-demo --reset
		 *
		 * @when after_wp_load
		 *
		 * @param array<int,string>    $args       Positional args (unused).
		 * @param array<string,string> $assoc_args Flags.
		 * @return void
		 */
		public function __invoke( $args, $assoc_args ) {
			if ( ! empty( $assoc_args['reset'] ) ) {
				$this->reset();
				return;
			}
			if ( ! class_exists( 'WooCommerce' ) ) {
				WP_CLI::error( 'WooCommerce must be active to seed the demo store.' );
			}
			$this->seed();
		}

		/**
		 * Create/update every part of the demo store, then persist the manifest.
		 *
		 * @return void
		 */
		private function seed(): void {
			$fixtures = self::fixtures();
			$manifest = self::load_manifest();

			$this->ensure_wc_pages();
			$this->seed_categories( $fixtures, $manifest );
			$this->seed_products( $fixtures, $manifest );
			$this->seed_addon_groups( $fixtures, $manifest );
			$this->write_business_info( $fixtures );
			$this->write_order_hours( $fixtures );
			$this->seed_area( $fixtures, $manifest );
			$this->seed_branch( $fixtures, $manifest );
			$this->seed_menu_page( $fixtures, $manifest );
			$this->enable_flags( $fixtures );

			$manifest['seeded_at'] = gmdate( 'c' );
			self::save_manifest( $manifest );

			$products = isset( $manifest['ids']['products'] ) ? count( $manifest['ids']['products'] ) : 0;
			WP_CLI::success( sprintf( 'Demo store seeded: %d products across %d categories, addons + branch + delivery zone + hours ready.', $products, count( $fixtures['categories'] ) ) );
		}

		/**
		 * Ensure the core WooCommerce pages exist (shop/cart/checkout/my-account).
		 * Owned by WooCommerce, so the seeder does NOT track them for --reset.
		 *
		 * @return void
		 */
		private function ensure_wc_pages(): void {
			if ( class_exists( 'WC_Install' ) && method_exists( 'WC_Install', 'create_pages' ) ) {
				WC_Install::create_pages();
				WP_CLI::log( 'Ensured WooCommerce pages (shop/cart/checkout/my-account).' );
			}
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_categories( array $fixtures, array &$manifest ): void {
			foreach ( $fixtures['categories'] as $cat ) {
				$existing = get_term_by( 'slug', $cat['slug'], 'product_cat' );
				if ( $existing && isset( $existing->term_id ) ) {
					wp_update_term(
						(int) $existing->term_id,
						'product_cat',
						array(
							'name'        => $cat['name'],
							'description' => $cat['description'],
						)
					);
					$term_id = (int) $existing->term_id;
				} else {
					$res = wp_insert_term(
						$cat['name'],
						'product_cat',
						array(
							'slug'        => $cat['slug'],
							'description' => $cat['description'],
						)
					);
					if ( is_wp_error( $res ) ) {
						WP_CLI::warning( sprintf( 'Category "%s": %s', $cat['slug'], $res->get_error_message() ) );
						continue;
					}
					$term_id = (int) $res['term_id'];
				}
				$manifest = self::record( $manifest, 'categories', $cat['slug'], $term_id );
			}
			WP_CLI::log( sprintf( 'Seeded %d product categories.', count( $fixtures['categories'] ) ) );
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_products( array $fixtures, array &$manifest ): void {
			foreach ( $fixtures['products'] as $product_data ) {
				$existing_id = (int) wc_get_product_id_by_sku( $product_data['sku'] );
				$is_variable = ( 'variable' === $product_data['type'] );

				if ( $existing_id > 0 ) {
					$product = wc_get_product( $existing_id );
					// If the stored type no longer matches, drop and recreate.
					if ( ! $product || $product->get_type() !== $product_data['type'] ) {
						wp_delete_post( $existing_id, true );
						$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
					}
				} else {
					$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
				}

				$product->set_name( $product_data['name'] );
				$product->set_slug( $product_data['slug'] );
				$product->set_sku( $product_data['sku'] );
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'visible' );
				$product->set_description( $product_data['description'] );
				$product->set_short_description( $product_data['short_description'] );

				$cat_id = self::recorded_id( $manifest, 'categories', $product_data['category'] );
				if ( $cat_id > 0 ) {
					$product->set_category_ids( array( $cat_id ) );
				}

				if ( $is_variable ) {
					$sizes     = $product_data['attributes']['Size'];
					$attribute = new WC_Product_Attribute();
					$attribute->set_name( 'Size' );
					$attribute->set_options( $sizes );
					$attribute->set_visible( true );
					$attribute->set_variation( true );
					$product->set_attributes( array( $attribute ) );
				} else {
					$product->set_regular_price( $product_data['price'] );
				}

				$product_id = (int) $product->save();

				$attachment_id = $this->ensure_image( $product_data['slug'], $product_data['name'], $manifest );
				if ( $attachment_id > 0 ) {
					$product->set_image_id( $attachment_id );
					$product->save();
				}

				if ( $is_variable ) {
					$this->sync_variations( $product_id, $product_data['variations'] );
				}

				$manifest = self::record( $manifest, 'products', $product_data['slug'], $product_id );
			}
			WP_CLI::log( sprintf( 'Seeded %d products.', count( $fixtures['products'] ) ) );
		}

		/**
		 * Replace a variable product's variations deterministically: delete the
		 * current children, then recreate one variation per fixture row.
		 *
		 * @param int                        $product_id Parent product id.
		 * @param array<int,array<string,mixed>> $variations Variation rows.
		 * @return void
		 */
		private function sync_variations( int $product_id, array $variations ): void {
			$parent = wc_get_product( $product_id );
			if ( $parent instanceof WC_Product_Variable ) {
				foreach ( $parent->get_children() as $child_id ) {
					wp_delete_post( (int) $child_id, true );
				}
			}
			foreach ( $variations as $row ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( array( 'size' => (string) $row['Size'] ) );
				$variation->set_regular_price( (string) $row['price'] );
				$variation->set_status( 'publish' );
				$variation->save();
			}
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $product_id );
			}
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_addon_groups( array $fixtures, array &$manifest ): void {
			foreach ( $fixtures['addon_groups'] as $set ) {
				$existing = get_posts(
					array(
						'post_type'   => self::ADDON_POST_TYPE,
						'title'       => $set['title'],
						'post_status' => 'any',
						'numberposts' => 1,
					)
				);
				$post_id = ( is_array( $existing ) && ! empty( $existing ) ) ? (int) $existing[0]->ID : 0;

				if ( $post_id > 0 ) {
					wp_update_post(
						array(
							'ID'          => $post_id,
							'post_title'  => $set['title'],
							'post_status' => 'publish',
						)
					);
				} else {
					$post_id = (int) wp_insert_post(
						array(
							'post_title'  => $set['title'],
							'post_type'   => self::ADDON_POST_TYPE,
							'post_status' => 'publish',
						)
					);
				}
				if ( $post_id <= 0 ) {
					continue;
				}

				update_post_meta( $post_id, '_product_addons', $set['product_addons'] );
				update_post_meta( $post_id, '_priority', $set['priority'] );

				$cat_id = self::recorded_id( $manifest, 'categories', $set['category'] );
				if ( $cat_id > 0 ) {
					wp_set_object_terms( $post_id, array( $cat_id ), 'product_cat' );
				}

				$manifest = self::record( $manifest, 'addon_groups', $set['slug'], $post_id );
			}
			WP_CLI::log( sprintf( 'Seeded %d addon groups (flat-per-option + flat-group).', count( $fixtures['addon_groups'] ) ) );
		}

		/**
		 * Write the fake-but-schema-valid business info through the same
		 * lafka_business_* options the WooCommerce Restaurant tab writes.
		 *
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @return void
		 */
		private function write_business_info( array $fixtures ): void {
			foreach ( $fixtures['business'] as $key => $value ) {
				update_option( $key, $value );
			}
			WP_CLI::log( 'Wrote demo business info (fake but schema-valid).' );
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @return void
		 */
		private function write_order_hours( array $fixtures ): void {
			update_option( 'lafka_order_hours_options', $fixtures['order_hours'] );
			WP_CLI::log( 'Wrote always-open order-hours schedule.' );
		}

		/**
		 * Enable the demo feature flags (order_hours + shipping_areas) in the
		 * shared 'lafka' option so the seeded store exercises the gates.
		 *
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @return void
		 */
		private function enable_flags( array $fixtures ): void {
			$lafka = get_option( 'lafka', array() );
			if ( ! is_array( $lafka ) ) {
				$lafka = array();
			}
			foreach ( $fixtures['flags'] as $flag => $value ) {
				$lafka[ $flag ] = $value;
			}
			update_option( 'lafka', $lafka );
			if ( class_exists( 'Lafka_Options' ) ) {
				Lafka_Options::flush();
			}
			WP_CLI::log( 'Enabled order_hours + shipping_areas feature flags.' );
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_area( array $fixtures, array &$manifest ): void {
			$area   = $fixtures['area'];
			$points = self::square_polygon( (float) $area['lat'], (float) $area['lng'], (float) $area['half_delta'] );
			$poly   = self::encode_polygon_coordinates( $points );

			$existing = get_posts(
				array(
					'post_type'   => self::AREA_POST_TYPE,
					'title'       => $area['title'],
					'post_status' => 'any',
					'numberposts' => 1,
				)
			);
			$post_id = ( is_array( $existing ) && ! empty( $existing ) ) ? (int) $existing[0]->ID : 0;

			if ( $post_id > 0 ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_title'  => $area['title'],
						'post_status' => 'publish',
					)
				);
			} else {
				$post_id = (int) wp_insert_post(
					array(
						'post_title'  => $area['title'],
						'post_type'   => self::AREA_POST_TYPE,
						'post_status' => 'publish',
					)
				);
			}
			if ( $post_id > 0 ) {
				update_post_meta( $post_id, '_lafka_shipping_area_polygon_coordinates', $poly );
				$manifest = self::record( $manifest, 'areas', $area['slug'], $post_id );
			}
			WP_CLI::log( 'Seeded delivery-zone polygon around the fake centre.' );
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_branch( array $fixtures, array &$manifest ): void {
			$branch   = $fixtures['branch'];
			$existing = get_term_by( 'slug', $branch['slug'], self::BRANCH_TAXONOMY );

			if ( $existing && isset( $existing->term_id ) ) {
				wp_update_term( (int) $existing->term_id, self::BRANCH_TAXONOMY, array( 'name' => $branch['name'] ) );
				$term_id = (int) $existing->term_id;
			} else {
				$res = wp_insert_term( $branch['name'], self::BRANCH_TAXONOMY, array( 'slug' => $branch['slug'] ) );
				if ( is_wp_error( $res ) ) {
					WP_CLI::warning( sprintf( 'Branch "%s": %s', $branch['slug'], $res->get_error_message() ) );
					return;
				}
				$term_id = (int) $res['term_id'];
			}

			foreach ( $branch['meta'] as $meta_key => $meta_value ) {
				update_term_meta( $term_id, $meta_key, $meta_value );
			}
			// Internal linkage id shipping-area assignments reference.
			update_term_meta( $term_id, 'branch_id', (string) $term_id );

			// Link the branch to the seeded delivery zone (JSON list of area ids).
			$area_id = self::recorded_id( $manifest, 'areas', $fixtures['area']['slug'] );
			if ( $area_id > 0 ) {
				update_term_meta( $term_id, 'lafka_branch_shipping_areas', wp_json_encode( array( (string) $area_id ) ) );
			}

			$manifest = self::record( $manifest, 'branches', $branch['slug'], $term_id );
			WP_CLI::log( 'Seeded one branch location.' );
		}

		/**
		 * @param array<string,mixed> $fixtures Fixture data.
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return void
		 */
		private function seed_menu_page( array $fixtures, array &$manifest ): void {
			$page      = $fixtures['page_menu'];
			$existing  = get_page_by_path( $page['slug'] );
			$page_args = array(
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			);

			if ( $existing && isset( $existing->ID ) ) {
				$page_args['ID'] = (int) $existing->ID;
				$page_id         = (int) wp_update_post( $page_args );
			} else {
				$page_id = (int) wp_insert_post( $page_args );
			}
			if ( $page_id > 0 ) {
				$manifest = self::record( $manifest, 'pages', $page['slug'], $page_id );
			}
			WP_CLI::log( 'Ensured /menu/ page.' );
		}

		/**
		 * Generate a tiny solid-colour placeholder PNG (with the item's initial
		 * when GD text is available), sideload it into the media library, and
		 * return the attachment id. Deterministic per key: a previously-seeded
		 * image recorded in the manifest is reused rather than regenerated. No
		 * bundled binaries, no remote fetches.
		 *
		 * @param string              $key      Stable object key (product slug).
		 * @param string              $label    Human label (alt text / initial).
		 * @param array<string,mixed> $manifest Manifest (by reference).
		 * @return int Attachment id (0 on failure).
		 */
		private function ensure_image( string $key, string $label, array &$manifest ): int {
			$recorded = self::recorded_id( $manifest, 'attachments', $key );
			if ( $recorded > 0 && get_post( $recorded ) ) {
				return $recorded;
			}
			if ( ! function_exists( 'imagecreatetruecolor' ) ) {
				return 0;
			}

			$data = $this->render_placeholder_png( $key, $label );
			if ( '' === $data ) {
				return 0;
			}

			$upload = wp_upload_bits( 'demo-' . $key . '.png', null, $data );
			if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
				return 0;
			}

			$filetype   = wp_check_filetype( $upload['file'], null );
			$attachment = array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/png',
				'post_title'     => $label,
				'post_content'   => '',
				'post_status'    => 'inherit',
			);
			$attachment_id = (int) wp_insert_attachment( $attachment, $upload['file'] );
			if ( $attachment_id <= 0 ) {
				return 0;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';
			$meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $meta );
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $label );

			$manifest = self::record( $manifest, 'attachments', $key, $attachment_id );
			return $attachment_id;
		}

		/**
		 * Render an 800x600 solid-colour PNG whose colour is deterministic in the
		 * key, with the label's initial drawn in the centre.
		 *
		 * @param string $key   Stable key (drives the colour).
		 * @param string $label Human label (drives the initial).
		 * @return string Raw PNG bytes, or '' on failure.
		 */
		private function render_placeholder_png( string $key, string $label ): string {
			$image = imagecreatetruecolor( 800, 600 );
			if ( false === $image ) {
				return '';
			}
			$hash       = md5( $key );
			$background  = imagecolorallocate(
				$image,
				(int) hexdec( substr( $hash, 0, 2 ) ),
				(int) hexdec( substr( $hash, 2, 2 ) ),
				(int) hexdec( substr( $hash, 4, 2 ) )
			);
			imagefill( $image, 0, 0, $background );

			$foreground = imagecolorallocate( $image, 255, 255, 255 );
			$letters    = preg_replace( '/[^A-Za-z]/', '', $label );
			$initial    = '' !== (string) $letters ? strtoupper( substr( (string) $letters, 0, 1 ) ) : 'D';
			$font       = 5;
			$x          = (int) ( ( 800 - imagefontwidth( $font ) ) / 2 );
			$y          = (int) ( ( 600 - imagefontheight( $font ) ) / 2 );
			imagestring( $image, $font, $x, $y, $initial, $foreground );

			ob_start();
			imagepng( $image );
			$data = (string) ob_get_clean();
			imagedestroy( $image );
			return $data;
		}

		// ─── Reset ───────────────────────────────────────────────────────────

		/**
		 * Delete exactly the objects a previous seed created (tracked ids), then
		 * drop the manifest. WooCommerce core pages and the business/hours/flag
		 * options are left untouched — a re-seed overwrites them in place.
		 *
		 * @return void
		 */
		private function reset(): void {
			$manifest = self::load_manifest();
			$deleted  = 0;

			foreach ( array( 'products', 'addon_groups', 'areas', 'pages', 'attachments' ) as $bucket ) {
				foreach ( $manifest['ids'][ $bucket ] as $id ) {
					$id = (int) $id;
					if ( $id <= 0 ) {
						continue;
					}
					if ( 'attachments' === $bucket ) {
						wp_delete_attachment( $id, true );
					} else {
						wp_delete_post( $id, true );
					}
					++$deleted;
				}
			}

			foreach ( $manifest['ids']['categories'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					wp_delete_term( $id, 'product_cat' );
					++$deleted;
				}
			}
			foreach ( $manifest['ids']['branches'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					wp_delete_term( $id, self::BRANCH_TAXONOMY );
					++$deleted;
				}
			}

			delete_option( self::MANIFEST_OPTION );
			WP_CLI::success( sprintf( 'Removed %d seeded objects and cleared the seed manifest.', $deleted ) );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'lafka seed-demo', 'Lafka_CLI_Seed_Demo' );
}
