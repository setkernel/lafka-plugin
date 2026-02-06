<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Report_Stock' ) ) {
	require_once( WC_ABSPATH . 'includes/admin/reports/class-wc-report-stock.php' );
}

/**
 * WC_LafkaCombos_Report_Insufficient_Stock class.
 *
 * Handles reporting of combos with an "Insufficient stock" status.
 *
 * @version  6.5.0
 */
class WC_LafkaCombos_Report_Insufficient_Stock extends WC_Report_Stock {

	/**
	 * Combo IDs sorted by title.
	 * @var array
	 */
	private $ordered_combo_ids = array();

	/*
	 * No items found text.
	 */
	public function no_items() {
		esc_html_e( 'No products found with insufficient stock.', 'lafka-plugin' );
	}

	/**
	 * Get combos matching "Insufficient stock" stock status criteria.
	 *
	 * @param  int  $current_page
	 * @param  int  $per_page
	 */
	public function get_items( $current_page, $per_page ) {

		global $wpdb;

		$this->max_items = 0;
		$this->items     = array();

		/*
		 * First, sync any combined items without stock meta.
		 */
		if ( ! defined( 'WC_LafkaCombos_DEBUG_STOCK_PARENT_SYNC' ) && ! defined( 'WC_LafkaCombos_DEBUG_STOCK_SYNC' ) ) {

			$data_store = WC_Data_Store::load( 'product-combo' );
			$sync_ids   = $data_store->get_combined_items_stock_sync_status_ids( 'unsynced' );

		} elseif ( ! defined( 'WC_LafkaCombos_DEBUG_STOCK_SYNC' ) ) {

			$sync_ids = WC_LafkaCombos_DB::query_combined_items( array(
				'return'          => 'id=>combo_id',
				'meta_query'      => array(
					array(
						'key'     => 'stock_status',
						'compare' => 'NOT EXISTS'
					),
				)
			) );

		} else {

			$sync_ids = WC_LafkaCombos_DB::query_combined_items( array(
				'return' => 'id=>combo_id'
			) );
		}

		if ( ! empty( $sync_ids ) ) {
			foreach ( $sync_ids as $id ) {
				if ( ( $product = wc_get_product( $id ) ) && $product->is_type( 'combo' ) ) {
					$product->sync_stock();
				}
			}
		}

		/*
		 * Then, get all combined items with insufficient stock.
		 */
		$insufficient_stock_results = WC_LafkaCombos_DB::query_combined_items( array(
			'return'          => 'all',
			'combo_id'       => ! empty( $_GET[ 'combo_id' ] ) ? absint( $_GET[ 'combo_id' ] ) : 0,
			'order_by'        => array( 'combo_id' => 'ASC', 'menu_order' => 'ASC' ),
			'meta_query'      => array(
				array(
					'key'     => 'stock_status',
					'value'   => 'out_of_stock',
					'compare' => '='
				),
			)
		) );

		if ( ! empty( $insufficient_stock_results ) ) {

			// Order results by combo title.

			$insufficient_stock_combo_ids = array_unique( wp_list_pluck( $insufficient_stock_results, 'combo_id' ) );

			$this->ordered_combo_ids = get_posts( array(
				'post_type'   => 'product',
				'post_status' => 'any',
				'orderby'     => 'title',
				'order'       => 'ASC',
				'post__in'    => $insufficient_stock_combo_ids,
				'fields'      => 'ids',
				'numberposts' => -1
			) );

			$insufficient_stock_results = array_filter( $insufficient_stock_results, array( $this, 'clean_missing_combos' ) );

			uasort( $insufficient_stock_results, array( $this, 'order_by_combo_title' ) );

			$insufficient_stock_results_in_page = array_slice( $insufficient_stock_results, ( $current_page - 1 ) * $per_page, $per_page );

			// Generate results data.

			foreach ( $insufficient_stock_results_in_page as $insufficient_stock_result_in_page ) {

				$combined_item = wc_pc_get_combined_item( $insufficient_stock_result_in_page[ 'combined_item_id' ] );

				if ( ! $combined_item ) {
					continue;
				}

				$item = new stdClass();

				$item->id           = $insufficient_stock_result_in_page[ 'product_id' ];
				$item->parent       = $insufficient_stock_result_in_page[ 'combo_id' ];
				$item->combined_item = $combined_item;
				$this->items[]      = $item;
			}

			$this->max_items = sizeof( $insufficient_stock_results );
		}
	}

	/**
	 * Clean up missing combos.
	 *
	 * @since  5.10.0
	 *
	 * @param  array  $a
	 * @return boolean
	 */
	private function clean_missing_combos( $result ) {
		return in_array( $result[ 'combo_id' ], $this->ordered_combo_ids );
	}

	/**
	 * Sorting callback - see 'get_items'.
	 *
	 * @param  array  $a
	 * @param  array  $b
	 * @return integer
	 */
	private function order_by_combo_title( $a, $b ) {

		$combo_id_a = $a[ 'combo_id' ];
		$combo_id_b = $b[ 'combo_id' ];

		$combo_id_a_index = array_search( $combo_id_a, $this->ordered_combo_ids );
		$combo_id_b_index = array_search( $combo_id_b, $this->ordered_combo_ids );

		if ( $combo_id_a_index === $combo_id_b_index ) {
			return 0;
		}

		return ( $combo_id_a_index < $combo_id_b_index ) ? -1 : 1;
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {

		$columns = array(
			'title'                => __( 'Combined product', 'lafka-plugin' ),
			'combo_title'         => __( 'Combo', 'lafka-plugin' ),
			'required_stock_level' => __( 'Units required', 'lafka-plugin' ),
			'stock_status'         => __( 'Stock status', 'woocommerce' ),
			'wc_actions'           => __( 'Actions', 'woocommerce' ),
		);

		return $columns;
	}

	/**
	 * Renders column values.
	 *
	 * @param  object  $item
	 * @param  string  $column_name
	 * @return void
	 */
	public function column_default( $item, $column_name ) {

		if ( 'title' === $column_name ) {

			$combined_item = $item->combined_item;
			$title        = $combined_item->product->get_title();

			if ( $combined_item->has_title_override() ) {
				$combined_item_title = $combined_item->get_title();
				if ( '' !== $combined_item_title ) {
					$title = $title . ' (' . $combined_item_title . ')';
				}
			}

			echo $title;

		} elseif ( 'combo_title' === $column_name ) {

			$combined_item = $item->combined_item;
			$edit_link    = get_edit_post_link( $combined_item->get_combo_id() );
			$title        = $combined_item->get_combo()->get_title();

			echo '<a class="item" href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a>';

		} elseif ( 'required_stock_level' === $column_name ) {

			echo $item->combined_item->get_quantity();

		} else {
			parent::column_default( $item, $column_name );
		}
	}
}
