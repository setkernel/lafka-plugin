<?php
/**
 * Lafka_Engine_Editor — renders the addon-group editor form and processes
 * its save POST.
 *
 * Save flow:
 *   1. Verify nonce + capability.
 *   2. Persist post-level meta (title, priority, applies-to categories,
 *      _all_products flag) via wp_insert_post / wp_update_post.
 *   3. Parse $_POST into Lafka_Addon_Group[] using the engine schema.
 *   4. Run each group through the resolved pricing strategy's expand()
 *      so options[i]['price'] gets the canonical shape (scalar or matrix).
 *   5. Persist via Lafka_Addons_Engine::repository()->save_groups().
 *
 * Render flow: builds groups from existing meta (or one fresh group on
 * "add" mode), passes to view templates.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Editor {

	const NONCE_ACTION = 'lafka_engine_save_addons';

	/**
	 * Entry point — called from Lafka_Engine_Admin::render() when the page
	 * is in add-or-edit mode.
	 */
	public function dispatch(): void {
		$edit_id = ! empty( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

		// Save submission.
		if ( ! empty( $_POST ) && check_admin_referer( self::NONCE_ACTION ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You do not have permission to save addons.', 'lafka-plugin' ) );
			}
			$saved_id = $this->save( $edit_id );
			if ( $saved_id ) {
				echo '<div class="updated"><p>' . esc_html__( 'Add-on saved.', 'lafka-plugin' ) . '</p></div>';
				$edit_id = $saved_id;
			}
		}

		// Resolve render context.
		$context = $this->build_render_context( $edit_id );
		require __DIR__ . '/views/global-edit.php';
	}

	/**
	 * @return array{
	 *   edit_id: int,
	 *   reference: string,
	 *   priority: int,
	 *   applies_to_all: bool,
	 *   category_ids: int[],
	 *   groups: Lafka_Addon_Group[],
	 *   product_attributes: array<int, object>,
	 *   product_categories: array<int, object>,
	 * }
	 */
	private function build_render_context( int $edit_id ): array {
		$reference         = '';
		$priority          = 10;
		$applies_to_all    = true;
		$category_ids      = array();
		$groups            = array();
		$product_attributes = function_exists( 'wc_get_attribute_taxonomies' )
			? wc_get_attribute_taxonomies()
			: array();
		$product_categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( $product_categories instanceof WP_Error ) {
			$product_categories = array();
		}

		if ( $edit_id > 0 ) {
			$post = get_post( $edit_id );
			if ( $post ) {
				$reference   = $post->post_title;
				$priority    = (int) get_post_meta( $edit_id, '_priority', true );
				$applies_to_all = (string) get_post_meta( $edit_id, '_all_products', true ) === '1';
				$category_ids = wp_get_post_terms( $edit_id, 'product_cat', array( 'fields' => 'ids' ) );
				if ( $category_ids instanceof WP_Error ) {
					$category_ids = array();
				}
				$groups = Lafka_Addons_Engine::instance()->repository()->get_groups( $edit_id );
			}
		}

		// Always provide at least one empty group so the form has something to render.
		if ( empty( $groups ) ) {
			$groups = array( Lafka_Addon_Group::from_array( array() ) );
		}

		return compact(
			'edit_id',
			'reference',
			'priority',
			'applies_to_all',
			'category_ids',
			'groups',
			'product_attributes',
			'product_categories'
		);
	}

	/**
	 * Save the editor POST. Returns the addon-group post ID on success, 0 on
	 * failure. Insert + update both flow through this single method.
	 */
	private function save( int $edit_id ): int {
		$post_data = wp_unslash( $_POST );

		$reference      = sanitize_text_field( $post_data['lafka_addon_reference'] ?? '' );
		$priority       = isset( $post_data['lafka_addon_priority'] ) ? (int) $post_data['lafka_addon_priority'] : 10;
		$applies_to_all = ! empty( $post_data['lafka_addon_applies_to_all'] );
		$category_ids   = isset( $post_data['lafka_addon_categories'] )
			? array_map( 'absint', (array) $post_data['lafka_addon_categories'] )
			: array();

		if ( $applies_to_all ) {
			$category_ids = array();
		}

		if ( '' === trim( $reference ) ) {
			$reference = sprintf(
				/* translators: %d: post id placeholder */
				__( 'Lafka Addon Group #%d', 'lafka-plugin' ),
				$edit_id ?: ( wp_count_posts( 'lafka_glb_addon' )->publish + 1 )
			);
		}

		// Insert or update the post container.
		if ( $edit_id > 0 && get_post_type( $edit_id ) === 'lafka_glb_addon' ) {
			wp_update_post(
				array(
					'ID'         => $edit_id,
					'post_title' => $reference,
				)
			);
		} else {
			$edit_id = (int) wp_insert_post(
				array(
					'post_title'  => $reference,
					'post_status' => 'publish',
					'post_type'   => 'lafka_glb_addon',
				)
			);
			if ( $edit_id <= 0 ) {
				return 0;
			}
		}

		wp_set_post_terms( $edit_id, $category_ids, 'product_cat', false );
		update_post_meta( $edit_id, '_all_products', $applies_to_all ? 1 : 0 );
		update_post_meta( $edit_id, '_priority', max( 0, $priority ) );

		// Parse and persist the addon-group payload.
		$groups = $this->parse_groups( $post_data );
		$groups = $this->expand_groups( $groups );

		Lafka_Addons_Engine::instance()->repository()->save_groups( $edit_id, $groups );

		return $edit_id;
	}

	/**
	 * Parse the editor's $_POST payload into Lafka_Addon_Group[].
	 *
	 * Form shape: $_POST['lafka_addon_groups'] is an indexed array of
	 * group dicts, each with its own `options` array.
	 *
	 * @return Lafka_Addon_Group[]
	 */
	private function parse_groups( array $post_data ): array {
		$raw_groups = isset( $post_data['lafka_addon_groups'] ) && is_array( $post_data['lafka_addon_groups'] )
			? $post_data['lafka_addon_groups']
			: array();

		$groups = array();
		foreach ( $raw_groups as $position => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$groups[] = $this->parse_one_group( $raw, (int) $position );
		}
		return $groups;
	}

	private function parse_one_group( array $raw, int $position ): Lafka_Addon_Group {
		$pricing_modes = Lafka_Addon_Schema::pricing_modes();
		$sources       = Lafka_Addon_Schema::options_sources();

		$pricing_mode = isset( $raw['pricing_mode'] ) && in_array( $raw['pricing_mode'], $pricing_modes, true )
			? (string) $raw['pricing_mode']
			: Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION;

		$source = isset( $raw['options_source'] ) && in_array( $raw['options_source'], $sources, true )
			? (string) $raw['options_source']
			: Lafka_Addon_Schema::SOURCE_MANUAL;

		$included_size_slugs = array();
		if ( isset( $raw['included_size_slugs'] ) && is_array( $raw['included_size_slugs'] ) ) {
			$included_size_slugs = array_values(
				array_map( 'sanitize_title', array_filter( $raw['included_size_slugs'], 'is_string' ) )
			);
		}

		$group_size_prices = array();
		if ( isset( $raw['group_size_prices'] ) && is_array( $raw['group_size_prices'] ) ) {
			foreach ( $raw['group_size_prices'] as $size_slug => $price ) {
				if ( ! is_string( $size_slug ) || '' === trim( (string) $price ) ) {
					continue;
				}
				$group_size_prices[ sanitize_title( $size_slug ) ] = wc_format_decimal( sanitize_text_field( (string) $price ) );
			}
		}

		$variations = ! empty( $raw['variations'] ) ? 1 : 0;
		$attribute  = isset( $raw['attribute'] ) ? (int) $raw['attribute'] : 0;

		$options = array();
		if ( isset( $raw['options'] ) && is_array( $raw['options'] ) ) {
			foreach ( $raw['options'] as $option_raw ) {
				if ( ! is_array( $option_raw ) ) {
					continue;
				}
				$options[] = $this->parse_one_option( $option_raw, $pricing_mode );
			}
		}

		return Lafka_Addon_Group::from_array(
			array(
				'name'                     => sanitize_text_field( $raw['name'] ?? '' ),
				'description'              => wp_kses_post( $raw['description'] ?? '' ),
				'type'                     => sanitize_key( $raw['type'] ?? 'checkbox' ),
				'limit'                    => isset( $raw['limit'] ) ? (int) $raw['limit'] : 0,
				'required'                 => ! empty( $raw['required'] ) ? 1 : 0,
				'position'                 => $position,
				'variations'               => $variations,
				'attribute'                => $attribute,
				'pricing_mode'             => $pricing_mode,
				'options_source'           => $source,
				'options_source_attribute' => sanitize_key( $raw['options_source_attribute'] ?? '' ),
				'included_size_slugs'      => $included_size_slugs,
				'group_flat_price'         => isset( $raw['group_flat_price'] ) && '' !== trim( (string) $raw['group_flat_price'] )
					? wc_format_decimal( sanitize_text_field( (string) $raw['group_flat_price'] ) )
					: '',
				'group_size_prices'        => $group_size_prices,
				'options'                  => array_map(
					static fn( Lafka_Addon_Option $o ) => $o->to_array(),
					$options
				),
			)
		);
	}

	private function parse_one_option( array $raw, string $pricing_mode ): Lafka_Addon_Option {
		$id      = isset( $raw['id'] ) && '' !== $raw['id']
			? sanitize_text_field( (string) $raw['id'] )
			: wp_generate_uuid4();
		$label   = sanitize_text_field( $raw['label'] ?? '' );
		$image   = isset( $raw['image'] ) ? absint( $raw['image'] ) : 0;
		$default = ! empty( $raw['default'] ) ? '1' : '';
		$included = ! isset( $raw['included'] ) || ! empty( $raw['included'] );

		// Per-option price field is only authoritative for flat_per_option
		// and matrix modes. Other modes use group-level fields and get
		// expanded by the strategy at save time. We still capture whatever
		// the operator entered so the editor can round-trip on re-render.
		$price = '';
		if ( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION === $pricing_mode ) {
			$price = isset( $raw['price'] ) && '' !== trim( (string) $raw['price'] )
				? wc_format_decimal( sanitize_text_field( (string) $raw['price'] ) )
				: '';
		} elseif ( Lafka_Addon_Schema::PRICING_MATRIX === $pricing_mode ) {
			$price = $this->parse_matrix_price( $raw );
		}

		return Lafka_Addon_Option::from_array(
			array(
				'id'       => $id,
				'label'    => $label,
				'image'    => (string) $image,
				'price'    => $price,
				'default'  => $default,
				'included' => $included,
			)
		);
	}

	/**
	 * Parse a per-option matrix price from POST.
	 *
	 * Form shape: options[N][matrix_price][taxonomy][slug] = '1.50'
	 *
	 * @param array $raw The raw option dict from POST.
	 * @return array<string, array<string, string>>|string
	 */
	private function parse_matrix_price( array $raw ) {
		if ( ! isset( $raw['matrix_price'] ) || ! is_array( $raw['matrix_price'] ) ) {
			return '';
		}
		$out = array();
		foreach ( $raw['matrix_price'] as $taxonomy => $by_slug ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $by_slug ) ) {
				continue;
			}
			$tax_key = sanitize_key( $taxonomy );
			foreach ( $by_slug as $slug => $price ) {
				if ( ! is_string( $slug ) || '' === trim( (string) $price ) ) {
					continue;
				}
				$out[ $tax_key ][ sanitize_title( $slug ) ] = wc_format_decimal( sanitize_text_field( (string) $price ) );
			}
		}
		return empty( $out ) ? '' : $out;
	}

	/**
	 * Run each group through its resolved strategy's expand() so the stored
	 * options[i]['price'] is in the canonical shape that downstream code
	 * (cart, display) expects regardless of mode.
	 *
	 * @param Lafka_Addon_Group[] $groups
	 * @return Lafka_Addon_Group[]
	 */
	private function expand_groups( array $groups ): array {
		$resolver = Lafka_Addons_Engine::instance()->pricing();
		$out      = array();
		foreach ( $groups as $group ) {
			$out[] = $resolver->for_group( $group )->expand( $group );
		}
		return $out;
	}
}
