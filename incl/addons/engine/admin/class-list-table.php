<?php
/**
 * Lafka_Engine_Addons_List_Table — WP_List_Table renderer for the global
 * addons admin page.
 *
 * Replaces the simple HTML table used through v8.13.x with WP's standard
 * list-table chrome: sortable columns, screen options, search, bulk actions
 * (placeholder for future bulk delete/sync), and pagination — all for free.
 *
 * Lazy-loaded only when the global addons admin page renders.
 *
 * @package Lafka_Addons_Engine
 * @since   8.14.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Lafka_Engine_Addons_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'lafka_addon_group',
				'plural'   => 'lafka_addon_groups',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'title'        => __( 'Name', 'lafka-plugin' ),
			'priority'     => __( 'Priority', 'lafka-plugin' ),
			'applies_to'   => __( 'Applies to', 'lafka-plugin' ),
			'group_count'  => __( 'Groups', 'lafka-plugin' ),
			'option_count' => __( 'Options', 'lafka-plugin' ),
			'modified'     => __( 'Modified', 'lafka-plugin' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'title'    => array( 'title', false ),
			'priority' => array( '_priority', false ),
			'modified' => array( 'modified', true ),
		);
	}

	public function prepare_items(): void {
		$per_page = 25;
		$paged    = max( 1, (int) ( $_REQUEST['paged'] ?? 1 ) );

		$orderby = sanitize_key( $_REQUEST['orderby'] ?? 'title' );
		$order   = strtolower( (string) ( $_REQUEST['order'] ?? 'asc' ) ) === 'desc' ? 'DESC' : 'ASC';
		$search  = sanitize_text_field( $_REQUEST['s'] ?? '' );

		$query_args = array(
			'post_type'      => 'lafka_glb_addon',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		if ( in_array( $orderby, array( '_priority' ), true ) ) {
			$query_args['meta_key'] = '_priority';
			$query_args['orderby']  = 'meta_value_num';
		}

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );

		$repo = Lafka_Addons_Engine::instance()->repository();
		$rows = array();
		foreach ( $query->posts as $post ) {
			$groups = $repo->get_groups( (int) $post->ID );
			$rows[] = array(
				'ID'           => (int) $post->ID,
				'title'        => $post->post_title,
				'priority'     => (int) get_post_meta( $post->ID, '_priority', true ),
				'all_products' => (string) get_post_meta( $post->ID, '_all_products', true ) === '1',
				'group_count'  => count( $groups ),
				'option_count' => array_sum( array_map( static fn( $g ) => count( $g->options ), $groups ) ),
				'modified'     => $post->post_modified,
				'category_ids' => wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'ids' ) ),
			);
		}

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $rows;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'priority':
				return (string) $item['priority'];
			case 'group_count':
				return (string) $item['group_count'];
			case 'option_count':
				return (string) $item['option_count'];
			case 'modified':
				return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['modified'] );
			default:
				return '';
		}
	}

	public function column_title( array $item ): string {
		$edit_url = add_query_arg(
			array(
				'post_type' => 'product',
				'page'      => Lafka_Engine_Admin::PAGE_SLUG,
				'edit'      => $item['ID'],
			),
			admin_url( 'edit.php' )
		);
		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type' => 'product',
					'page'      => Lafka_Engine_Admin::PAGE_SLUG,
					'delete'    => $item['ID'],
				),
				admin_url( 'edit.php' )
			),
			'delete_addon_' . $item['ID']
		);

		$actions = array(
			'edit'  => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'lafka-plugin' ) ),
			'trash' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Move this addon to trash?', 'lafka-plugin' ) ),
				esc_html__( 'Trash', 'lafka-plugin' )
			),
		);

		return sprintf(
			'<strong><a href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['title'] ),
			$this->row_actions( $actions )
		);
	}

	public function column_applies_to( array $item ): string {
		if ( $item['all_products'] ) {
			return esc_html__( 'All products', 'lafka-plugin' );
		}
		$cat_ids = is_array( $item['category_ids'] ) ? $item['category_ids'] : array();
		if ( empty( $cat_ids ) ) {
			return '<em>' . esc_html__( 'No categories', 'lafka-plugin' ) . '</em>';
		}
		$names = array();
		foreach ( $cat_ids as $cat_id ) {
			$term = get_term( (int) $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return esc_html( implode( ', ', $names ) );
	}

	public function no_items(): void {
		echo esc_html__( 'No addon groups found.', 'lafka-plugin' );
	}
}
