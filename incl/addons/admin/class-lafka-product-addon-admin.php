<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lafka_Product_Addon_Admin class.
 */
class Lafka_Product_Addon_Admin {

	/**
	 * Initialize administrative actions.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ), 100 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 9 );
		add_filter( 'woocommerce_screen_ids', array( $this, 'add_screen_id' ) );
		add_action( 'woocommerce_product_write_panel_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'process_meta_box' ), 1 );
	}

	/**
	 * Add menus
	 */
	public function admin_menu() {
		$page = add_submenu_page( 'edit.php?post_type=product', esc_html__( 'Lafka Global Add-ons', 'lafka-plugin' ), esc_html__( 'Lafka Global Add-ons', 'lafka-plugin' ), 'manage_woocommerce', 'lafka_global_addons', array( $this, 'global_addons_admin' ) );
	}

	/**
	 * Enqueue styles and scripts.
	 */
	public function styles() {
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'lafka_product_addons_css', plugins_url( '../assets/css/admin.css', __FILE__ ), array( 'woocommerce_admin_styles' ), filemtime( plugin_dir_path( __FILE__ ) . '../assets/css/admin.css' ) );

		// Enqueue the extracted addon admin panel script on screens that use it.
		$screen         = get_current_screen();
		$screen_id      = $screen ? $screen->id : '';
		$needs_panel_js = (
			'product' === $screen_id
			|| 'product_page_lafka_global_addons' === $screen_id
			|| 'lafka_product_page_global_addons' === $screen_id
		);

		if ( $needs_panel_js ) {
			$js_file = plugin_dir_path( __FILE__ ) . '../assets/js/lafka-addons-admin-panel.js';
			wp_enqueue_script(
				'lafka-addons-admin-panel',
				plugins_url( '../assets/js/lafka-addons-admin-panel.js', __FILE__ ),
				array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select' ),
				filemtime( $js_file ),
				true
			);

			// "New option" preview: template references $addon; pass a default
			// shape so the option-row resolver call in the template doesn't
			// trip over an undefined variable.
			$preview_addon = array(
				'name'        => '',
				'limit'       => '',
				'description' => '',
				'required'    => '',
				'attribute'   => 0,
				'type'        => 'checkbox',
				'variations'  => 0,
				'options'     => array(),
			);

			ob_start();
			$option = self::get_new_addon_option();
			$addon  = $preview_addon;
			$loop   = '{loop}';
			include plugin_dir_path( __FILE__ ) . 'views/html-addon-option.php';
			$new_option_html = str_replace( array( "\n", "\r" ), '', str_replace( "'", '"', ob_get_clean() ) );

			ob_start();
			$addon            = $preview_addon;
			$addon['options'] = array( self::get_new_addon_option() );
			$loop             = '{loop}';
			include plugin_dir_path( __FILE__ ) . 'views/html-addon.php';
			$new_addon_html = str_replace( array( "\n", "\r" ), '', str_replace( "'", '"', ob_get_clean() ) );

			wp_localize_script(
				'lafka-addons-admin-panel',
				'lafka_addons_admin_params',
				array(
					'empty_name_message'       => esc_html__( 'All addon fields require a name.', 'lafka-plugin' ),
					'min_max_characters_label' => esc_js( __( 'Min / max characters', 'lafka-plugin' ) ),
					'min_max_label'            => esc_js( __( 'Min / max', 'lafka-plugin' ) ),
					'new_option_html'          => $new_option_html,
					'new_addon_html'           => $new_addon_html,
					'remove_addon_confirm'     => esc_html__( 'Are you sure you want to remove this add-on?', 'lafka-plugin' ),
					'remove_option_confirm'    => esc_html__( 'Are you sure you want delete this option?', 'lafka-plugin' ),
					'price_label'              => esc_html__( 'Price', 'lafka-plugin' ),
				)
			);
		}
	}

	/**
	 * Add screen id to WooCommerce.
	 *
	 * @param array $screen_ids List of screen IDs.
	 * @return array
	 */
	public function add_screen_id( $screen_ids ) {
		$screen_ids[] = 'lafka_product_page_global_addons';

		return $screen_ids;
	}

	/**
	 * Controls the global addons admin page.
	 */
	public function global_addons_admin() {
		if ( ! empty( $_GET['add'] ) || ! empty( $_GET['edit'] ) ) {

			if ( $_POST ) {
				check_admin_referer( 'lafka_save_global_addons' );

				if ( $edit_id = $this->save_global_addons() ) {
					echo '<div class="updated"><p>' . esc_html__( 'Add-on saved successfully', 'lafka-plugin' ) . '</p></div>';
				}

				$reference      = wc_clean( $_POST['addon-reference'] );
				$priority       = absint( $_POST['addon-priority'] );
				$objects        = ! empty( $_POST['addon-objects'] ) ? array_map( 'absint', $_POST['addon-objects'] ) : array();
				$product_addons = array_filter( (array) $this->get_posted_product_addons() );
			}

			if ( ! empty( $_GET['edit'] ) ) {

				$edit_id      = absint( $_GET['edit'] );
				$global_addon = get_post( $edit_id );

				if ( ! $global_addon ) {
					echo '<div class="error">' . esc_html__( 'Error: Global Add-on not found', 'lafka-plugin' ) . '</div>';
					return;
				}

				$reference      = $global_addon->post_title;
				$priority       = get_post_meta( $global_addon->ID, '_priority', true );
				$objects        = (array) wp_get_post_terms( $global_addon->ID, apply_filters( 'lafka_product_addons_global_post_terms', array( 'product_cat' ) ), array( 'fields' => 'ids' ) );
				$product_addons = array_filter( (array) get_post_meta( $global_addon->ID, '_product_addons', true ) );

				if ( get_post_meta( $global_addon->ID, '_all_products', true ) == 1 ) {
					$objects[] = 0;
				}
			} elseif ( ! empty( $edit_id ) ) {

				$global_addon   = get_post( $edit_id );
				$reference      = $global_addon->post_title;
				$priority       = get_post_meta( $global_addon->ID, '_priority', true );
				$objects        = (array) wp_get_post_terms( $global_addon->ID, apply_filters( 'lafka_product_addons_global_post_terms', array( 'product_cat' ) ), array( 'fields' => 'ids' ) );
				$product_addons = array_filter( (array) get_post_meta( $global_addon->ID, '_product_addons', true ) );

				if ( get_post_meta( $global_addon->ID, '_all_products', true ) == 1 ) {
					$objects[] = 0;
				}
			} else {

				$global_addons_count = wp_count_posts( 'lafka_glb_addon' );
				$reference           = __( 'Global Add-on Group', 'lafka-plugin' ) . ' #' . ( $global_addons_count->publish + 1 );
				$priority            = 10;
				$objects             = array( 0 );
				$product_addons      = array();

			}

			include __DIR__ . '/views/html-global-admin-add.php';
		} else {

			if ( ! empty( $_GET['delete'] ) ) {
				$id = absint( $_GET['delete'] );

				// Validate target is an actual addon CPT (prevents IDOR / deleting any post by ID).
				if ( ! $id || 'lafka_glb_addon' !== get_post_type( $id ) ) {
					wp_die( esc_html__( 'Invalid addon ID.', 'lafka-plugin' ) );
				}

				// Per-ID nonce blocks blanket forged deletion links.
				$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
				if ( ! wp_verify_nonce( $nonce, 'delete_addon_' . $id ) ) {
					wp_die( esc_html__( 'Security check failed.', 'lafka-plugin' ) );
				}

				// Capability check — must be able to delete this specific post.
				if ( ! current_user_can( 'delete_post', $id ) ) {
					wp_die( esc_html__( 'You do not have permission to delete this addon.', 'lafka-plugin' ) );
				}

				// Trash (recoverable) instead of force-delete.
				wp_trash_post( $id );
				echo '<div class="updated"><p>' . esc_html__( 'Add-on moved to trash.', 'lafka-plugin' ) . '</p></div>';
			}

			include __DIR__ . '/views/html-global-admin.php';
		}
	}

	/**
	 * Save global addons
	 *
	 * @return bool success or failure
	 */
	public function save_global_addons() {
		$edit_id        = ! empty( $_POST['edit_id'] ) ? absint( $_POST['edit_id'] ) : '';
		$reference      = wc_clean( $_POST['addon-reference'] );
		$priority       = absint( $_POST['addon-priority'] );
		$objects        = ! empty( $_POST['addon-objects'] ) ? array_map( 'absint', $_POST['addon-objects'] ) : array();
		$product_addons = $this->get_posted_product_addons();

		if ( ! $reference ) {
			$global_addons_count = wp_count_posts( 'lafka_glb_addon' );
			$reference           = __( 'Global Add-on Group', 'lafka-plugin' ) . ' #' . ( $global_addons_count->publish + 1 );
		}

		if ( ! $priority && $priority !== 0 ) {
			$priority = 10;
		}

		if ( $edit_id ) {

			$edit_post               = array();
			$edit_post['ID']         = $edit_id;
			$edit_post['post_title'] = $reference;

			wp_update_post( $edit_post );
			wp_set_post_terms( $edit_id, $objects, 'product_cat', false );
			do_action( 'lafka_product_addons_global_edit_addons', $edit_post, $objects );

		} else {

			$edit_id = wp_insert_post(
				apply_filters(
					'lafka_product_addons_global_insert_post_args',
					array(
						'post_title'  => $reference,
						'post_status' => 'publish',
						'post_type'   => 'lafka_glb_addon',
						'tax_input'   => array(
							'product_cat' => $objects,
						),
					),
					$reference,
					$objects
				)
			);

		}

		if ( in_array( 0, $objects ) ) {
			update_post_meta( $edit_id, '_all_products', 1 );
		} else {
			update_post_meta( $edit_id, '_all_products', 0 );
		}

		update_post_meta( $edit_id, '_priority', $priority );

		// Defensive merge: preserve nested per-attribute price arrays when the
		// form rendered flat for any reason. Skipped on first insert (no existing).
		$existing_addons = (array) get_post_meta( $edit_id, '_product_addons', true );
		$product_addons  = self::preserve_nested_prices_on_save( $product_addons, $existing_addons );

		update_post_meta( $edit_id, '_product_addons', $product_addons );

		return $edit_id;
	}

	/**
	 * Add product tab.
	 */
	public function tab() {
		?><li class="addons_tab product_addons"><a href="#product_addons_data"><span><?php esc_html_e( 'Lafka Add-ons', 'lafka-plugin' ); ?></span></a></li>
		<?php
	}

	/**
	 * Add product panel.
	 */
	public function panel() {
		global $post;

		$product              = wc_get_product( $post );
		$exists               = (bool) $product->get_id();
		$product_addons       = array_filter( (array) $product->get_meta( '_product_addons' ) );
		$exclude_global       = $product->get_meta( '_product_addons_exclude_global' );
		$attribute_taxonomies = wc_get_attribute_taxonomies();

		include __DIR__ . '/views/html-addon-panel.php';
	}

	/**
	 * Process meta box.
	 *
	 * @param int $post_id Post ID.
	 */
	public function process_meta_box( $post_id ) {
		// Defense in depth: WC's metabox save handler does its own nonce + cap
		// check before firing `woocommerce_process_product_meta`, but this hook
		// also fires from non-admin paths (WC_AJAX, programmatic saves, REST
		// passthroughs). Guard independently so we never overwrite addon data
		// from a context that wasn't a real operator save.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// `product_addon_name` is the form's canonical "the addon panel was
		// rendered + posted" marker. If it's absent, this isn't an addon-panel
		// save — bail rather than blanket-clearing _product_addons.
		if ( ! isset( $_POST['product_addon_name'] ) ) {
			return;
		}

		// Save addons as serialised array.
		$product_addons                = $this->get_posted_product_addons();
		$product_addons_exclude_global = isset( $_POST['_product_addons_exclude_global'] ) ? 1 : 0;

		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// Read existing meta DIRECTLY from postmeta (not via $product->get_meta)
		// so the preserve-merge sees authoritative DB state. wc_get_product()
		// returns a cached object whose meta cache may already reflect an
		// in-progress update from earlier in the same request — which would
		// silently no-op the preserve guard. get_post_meta() bypasses the
		// product object's runtime cache.
		$existing_addons = (array) get_post_meta( $post_id, '_product_addons', true );
		$product_addons  = self::preserve_nested_prices_on_save( $product_addons, $existing_addons );

		$product->update_meta_data( '_product_addons', $product_addons );
		$product->update_meta_data( '_product_addons_exclude_global', $product_addons_exclude_global );
		$product->save();
	}

	/**
	 * Generate a filterable default new addon option.
	 *
	 * @return array
	 */
	public static function get_new_addon_option() {
		$new_addon_option = array(
			'id'      => wp_generate_uuid4(),
			'label'   => '',
			'image'   => '',
			'price'   => '',
			'default' => '',
			'min'     => '',
			'max'     => '',
		);

		return apply_filters( 'lafka_product_addons_new_addon_option', $new_addon_option );
	}

	public static function lafka_get_addons_variations_attribute_values( $taxonomy ) {
		$to_return = array();

		if ( taxonomy_exists( $taxonomy ) ) {
			// Modern array-form (WP 4.5+); positional first-arg scheduled for removal.
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					// Pass raw — every consumer template runs esc_html() on
					// these values. Pre-escaping with htmlspecialchars()
					// here produced double-encoded output (a term named
					// "Pizza & Pasta" rendered as "Pizza &amp;amp; Pasta").
					$to_return[ $taxonomy ][ $term->slug ] = $term->name;
				}
			}
		}

		return $to_return;
	}

	/**
	 * Resolve which attribute taxonomy this addon's per-attribute pricing is
	 * keyed by, returning the term-values map for column rendering.
	 *
	 * Two-step resolution:
	 *   1. Configured attribute (preferred) — $addon['attribute'] is a WC
	 *      attribute_taxonomy ID; look up its taxonomy slug.
	 *   2. Detected from data — if (1) yields nothing (attribute deleted, ID
	 *      stale, or never picked), iterate ALL options to find the first
	 *      whose price is a non-empty nested array; the first key is the
	 *      taxonomy. Iterating beyond option 0 matters: a partial regression
	 *      may have flattened option 0's price while later options still
	 *      pin the correct taxonomy.
	 *
	 * Returns [] when the addon doesn't use variation pricing OR no taxonomy
	 * could be resolved. Callers fall back to a single-price column.
	 *
	 * Accepts mixed because the option-row template is also used by styles()
	 * to build a "new option" preview without an addon in scope ($addon is
	 * undefined / null there). Strict typehints would fatal on that path.
	 *
	 * @param mixed $addon Addon group data shape from _product_addons meta, or null/scalar in preview contexts.
	 * @return array<string, array<string, string>> [ taxonomy => [ slug => name ] ]
	 */
	public static function resolve_addon_attribute_values( $addon ): array {
		if ( ! is_array( $addon ) ) {
			return array();
		}

		$has_variations = isset( $addon['variations'] ) && (int) $addon['variations'] === 1;
		if ( ! $has_variations ) {
			return array();
		}

		if ( ! empty( $addon['attribute'] ) ) {
			$taxonomy = wc_attribute_taxonomy_name_by_id( (int) $addon['attribute'] );
			if ( $taxonomy ) {
				$values = self::lafka_get_addons_variations_attribute_values( $taxonomy );
				if ( ! empty( $values ) ) {
					return $values;
				}
			}
		}

		if ( ! empty( $addon['options'] ) && is_array( $addon['options'] ) ) {
			foreach ( $addon['options'] as $option ) {
				if ( empty( $option['price'] ) || ! is_array( $option['price'] ) ) {
					continue;
				}
				$detected_taxonomy = (string) key( $option['price'] );
				if ( $detected_taxonomy && taxonomy_exists( $detected_taxonomy ) ) {
					$values = self::lafka_get_addons_variations_attribute_values( $detected_taxonomy );
					if ( ! empty( $values ) ) {
						return $values;
					}
				}
			}
		}

		return array();
	}

	/**
	 * Defensively merge freshly-posted addon groups against the existing meta,
	 * preserving per-attribute nested price arrays when the form rendered as
	 * a single flat price input.
	 *
	 * Why this exists: the admin form's price input shape depends on whether
	 * the per-term column rendering succeeds (configured attribute resolves +
	 * data-detected fallback). When it fails for any reason — stale attribute
	 * ID, deleted taxonomy, type-drift in serialized meta — the form falls
	 * back to a single flat input. The save handler then reads $_POST and
	 * builds a SCALAR price, irreversibly overwriting any nested array that
	 * was previously stored. Once flattened, the data-detection fallback
	 * can't reconstruct the taxonomy and every subsequent edit compounds the
	 * loss. This guard breaks the cycle.
	 *
	 * Match strategy:
	 *   - Addons matched by 'name' (loop position is unstable across reorders).
	 *   - Options matched by 'id' (UUID; stable across edits).
	 *
	 * Preserve rule: only when the new addon's variations === 1 (operator
	 * intends per-attribute pricing) AND new option price is scalar AND the
	 * existing matched option's price is a non-empty nested array.
	 *
	 * Operators can still legitimately remove per-attribute pricing by
	 * unchecking "Use in Variations" (then variations === 0 → no preservation).
	 *
	 * @param array $new_addons      Addons array built from $_POST.
	 * @param array $existing_addons Existing _product_addons meta from DB.
	 * @return array Merged addons array.
	 */
	public static function preserve_nested_prices_on_save( array $new_addons, array $existing_addons ): array {
		// `(array) ''` from get_post_meta() on an empty key produces [''], not [];
		// strip non-array entries so downstream offset access is safe.
		$existing_addons = array_filter( $existing_addons, 'is_array' );
		if ( empty( $existing_addons ) ) {
			return $new_addons;
		}

		foreach ( $new_addons as $addon_index => $new_addon ) {
			if ( ! is_array( $new_addon ) ) {
				continue;
			}
			if ( 1 !== (int) ( $new_addon['variations'] ?? 0 ) ) {
				continue;
			}
			if ( empty( $new_addon['options'] ) || ! is_array( $new_addon['options'] ) ) {
				continue;
			}

			$existing_match = null;
			foreach ( $existing_addons as $candidate ) {
				if ( ! empty( $candidate['name'] ) && ! empty( $new_addon['name'] )
					&& $candidate['name'] === $new_addon['name'] ) {
					$existing_match = $candidate;
					break;
				}
			}
			if ( ! $existing_match
				|| empty( $existing_match['options'] )
				|| ! is_array( $existing_match['options'] ) ) {
				continue;
			}

			$existing_options_by_id = array();
			foreach ( $existing_match['options'] as $existing_option ) {
				if ( is_array( $existing_option ) && ! empty( $existing_option['id'] ) ) {
					$existing_options_by_id[ $existing_option['id'] ] = $existing_option;
				}
			}
			if ( empty( $existing_options_by_id ) ) {
				continue;
			}

			$prices_restored = false;
			foreach ( $new_addon['options'] as $option_index => $new_option ) {
				if ( ! is_array( $new_option ) ) {
					continue;
				}
				if ( is_array( $new_option['price'] ?? null ) ) {
					continue;
				}
				if ( empty( $new_option['id'] ) || ! isset( $existing_options_by_id[ $new_option['id'] ] ) ) {
					continue;
				}
				$existing_option = $existing_options_by_id[ $new_option['id'] ];
				if ( ! is_array( $existing_option['price'] ?? null ) || empty( $existing_option['price'] ) ) {
					continue;
				}
				$new_addons[ $addon_index ]['options'][ $option_index ]['price'] = $existing_option['price'];
				$prices_restored = true;
			}

			// If we restored nested prices, re-align `attribute` to match the
			// taxonomy actually present in the merged data. Otherwise the next
			// render would fall through the configured-attribute path (because
			// $addon['attribute'] may be 0 or stale) and rely on data detection.
			// Aligning here means subsequent edits use the fast path and the
			// operator's attribute selector reflects reality.
			if ( $prices_restored && function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
				foreach ( $new_addons[ $addon_index ]['options'] as $merged_option ) {
					if ( empty( $merged_option['price'] ) || ! is_array( $merged_option['price'] ) ) {
						continue;
					}
					$detected_taxonomy = (string) key( $merged_option['price'] );
					if ( ! $detected_taxonomy || ! taxonomy_exists( $detected_taxonomy ) ) {
						continue;
					}
					$detected_id = (int) wc_attribute_taxonomy_id_by_name( $detected_taxonomy );
					if ( $detected_id > 0 ) {
						$new_addons[ $addon_index ]['attribute'] = $detected_id;
					}
					break;
				}
			}
		}

		return $new_addons;
	}

	/**
	 * Put posted addon data into an array.
	 *
	 * @return array
	 */
	protected function get_posted_product_addons() {
		$product_addons = array();

		if ( isset( $_POST['product_addon_name'] ) ) {
			$addon_name                = array_map( 'sanitize_text_field', wp_unslash( $_POST['product_addon_name'] ) );
			$addon_limit               = array_map( 'absint', $_POST['product_addon_limit'] );
			$addon_description         = array_map( 'sanitize_text_field', wp_unslash( $_POST['product_addon_description'] ) );
			$addon_type                = array_map( 'sanitize_text_field', wp_unslash( $_POST['product_addon_type'] ) );
			$addon_position            = array_map( 'sanitize_text_field', wp_unslash( $_POST['product_addon_position'] ) );
			$addon_variations          = isset( $_POST['product_addon_variations'] ) ? array_map( 'absint', $_POST['product_addon_variations'] ) : 0;
			$addon_variation_attribute = isset( $_POST['product_addon_variation_attribute'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['product_addon_variation_attribute'] ) ) : array();
			$addon_required            = isset( $_POST['product_addon_required'] ) ? array_map( 'absint', $_POST['product_addon_required'] ) : array();

			$addon_option_id    = isset( $_POST['product_addon_option_id'] ) ? array_map(
				function ( $ids ) {
					return array_map( 'sanitize_text_field', wp_unslash( $ids ) );
				},
				$_POST['product_addon_option_id']
			) : array();
			$addon_option_label = array_map(
				function ( $labels ) {
					return array_map( 'sanitize_text_field', wp_unslash( $labels ) );
				},
				$_POST['product_addon_option_label']
			);
			$addon_option_image = array_map(
				function ( $images ) {
					return array_map( 'absint', $images );
				},
				$_POST['product_addon_option_image']
			);
			$addon_option_price = array_map(
				function ( $prices ) {
					return array_map( 'wc_clean', $prices );
				},
				$_POST['product_addon_option_price']
			);

			$addon_option_min = array_map(
				function ( $mins ) {
					return array_map( 'absint', $mins );
				},
				$_POST['product_addon_option_min']
			);
			$addon_option_max = array_map(
				function ( $maxes ) {
					return array_map( 'absint', $maxes );
				},
				$_POST['product_addon_option_max']
			);

			$addon_option_default = array_map(
				function ( $defaults ) {
					return is_array( $defaults ) ? array_map( 'sanitize_text_field', $defaults ) : sanitize_text_field( $defaults );
				},
				wp_unslash( $_POST['product_addon_option_default'] )
			);

			for ( $i = 0; $i < sizeof( $addon_name ); $i++ ) {

				if ( ! isset( $addon_name[ $i ] ) || ( '' == $addon_name[ $i ] ) ) {
					continue;
				}

				$addon_options  = array();
				$option_id      = isset( $addon_option_id[ $i ] ) ? $addon_option_id[ $i ] : array();
				$option_label   = $addon_option_label[ $i ];
				$option_image   = $addon_option_image[ $i ];
				$option_price   = $addon_option_price[ $i ];
				$option_min     = $addon_option_min[ $i ];
				$option_max     = $addon_option_max[ $i ];
				$option_default = $addon_option_default[ $i ];

				for ( $ii = 0; $ii < sizeof( $option_label ); $ii++ ) {
					$id    = ! empty( $option_id[ $ii ] ) ? sanitize_text_field( $option_id[ $ii ] ) : wp_generate_uuid4();
					$label = sanitize_text_field( $option_label[ $ii ] );
					$image = sanitize_text_field( $option_image[ $ii ] );
					if ( isset( $option_price[ $ii ] ) ) {
						$price = wc_format_decimal( sanitize_text_field( $option_price[ $ii ] ) );
					} else {
						$price = array();
						foreach ( $option_price as $attribute_name => $attribute_value ) {
							foreach ( $attribute_value as $attribute_slug => $attribute_price ) {
								$price[ $attribute_name ][ $attribute_slug ] = wc_format_decimal( sanitize_text_field( $attribute_price[ $ii ] ) );
							}
						}
					}

					$min     = sanitize_text_field( $option_min[ $ii ] );
					$max     = sanitize_text_field( $option_max[ $ii ] );
					$default = sanitize_text_field( $option_default[ $ii ] );

					$addon_options[] = array(
						'id'      => $id,
						'label'   => $label,
						'image'   => $image,
						'price'   => $price,
						'min'     => $min,
						'max'     => $max,
						'default' => $default,
					);
				}

				if ( sizeof( $addon_options ) == 0 ) {
					continue; // Needs options.
				}

				$data                = array();
				$data['name']        = sanitize_text_field( $addon_name[ $i ] );
				$data['limit']       = sanitize_text_field( $addon_limit[ $i ] );
				$data['description'] = wp_kses_post( $addon_description[ $i ] );
				$data['type']        = sanitize_text_field( $addon_type[ $i ] );
				$data['position']    = absint( $addon_position[ $i ] );
				$data['variations']  = isset( $addon_variations[ $i ] ) ? 1 : 0;
				$data['attribute']   = absint( empty( $addon_variation_attribute[ $i ] ) ? 0 : $addon_variation_attribute[ $i ] );
				$data['options']     = $addon_options;
				$data['required']    = isset( $addon_required[ $i ] ) ? 1 : 0;

				// Self-heal: when variations=1 and the saved options[*]['price']
				// arrays are keyed by a real taxonomy ('pa_size' etc.), but the
				// configured `attribute` ID is 0 (operator never picked one) or
				// resolves to a different taxonomy, sync `attribute` to the
				// taxonomy actually present in the data. Keeps subsequent edits
				// rendering the correct columns instead of falling into the
				// "Array" / single-price branch.
				if ( 1 === $data['variations'] && ! empty( $addon_options ) ) {
					$first_price = $addon_options[0]['price'] ?? null;
					if ( is_array( $first_price ) && ! empty( $first_price ) ) {
						$detected_tax = (string) key( $first_price );
						if ( $detected_tax && taxonomy_exists( $detected_tax ) ) {
							$detected_id = function_exists( 'wc_attribute_taxonomy_id_by_name' )
								? (int) wc_attribute_taxonomy_id_by_name( $detected_tax )
								: 0;
							if ( $detected_id > 0 && $detected_id !== $data['attribute'] ) {
								$data['attribute'] = $detected_id;
							}
						}
					}
				}

				// Add to array.
				$product_addons[] = apply_filters( 'lafka_product_addons_save_data', $data, $i );
			}
		}

		uasort( $product_addons, array( $this, 'addons_cmp' ) );

		return $product_addons;
	}

	/**
	 * Sort addons.
	 *
	 * @param  array $a First item to compare.
	 * @param  array $b Second item to compare.
	 * @return bool
	 */
	protected function addons_cmp( $a, $b ) {
		if ( $a['position'] == $b['position'] ) {
			return 0;
		}

		return ( $a['position'] < $b['position'] ) ? -1 : 1;
	}
}
