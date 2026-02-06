<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'create_term', 'lafka_create_foodmenu_category', 5, 3 );
if ( ! function_exists( 'lafka_create_foodmenu_category' ) ) {
	/**
	 * Set 'order' field to 0 on new categories, so it appear first
	 *
	 * @param $term_id
	 * @param string $tt_id
	 * @param string $taxonomy
	 */
	function lafka_create_foodmenu_category( $term_id, $tt_id = '', $taxonomy = '' ) {
		if ( 'lafka_foodmenu_category' != $taxonomy ) {
			return;
		}

		update_term_meta( $term_id, 'order', 0 );
	}
}

// Add columns to lafka_foodmenu_category for sorting
add_filter( 'manage_edit-lafka_foodmenu_category_columns', 'lafka_foodmenu_category_columns' );
add_filter( 'manage_lafka_foodmenu_category_custom_column', 'lafka_foodmenu_category_column', 10, 3 );
if ( ! function_exists( 'lafka_foodmenu_category_columns' ) ) {
	function lafka_foodmenu_category_columns( $columns ) {
		$columns['handle'] = '';

		return $columns;
	}
}

if ( ! function_exists( 'lafka_foodmenu_category_column' ) ) {
	function lafka_foodmenu_category_column( $columns, $column, $id ) {
		if ( 'handle' === $column ) {
			$columns .= '<input type="hidden" name="term_id" value="' . esc_attr( $id ) . '" />';
		}

		return $columns;
	}
}

/**
 * Ajax request handling for categories ordering.
 */
add_action( 'wp_ajax_lafka_foodmenu_cat_ordering', 'lafka_foodmenu_cat_ordering' );
if ( ! function_exists( 'lafka_foodmenu_cat_ordering' ) ) {
	function lafka_foodmenu_cat_ordering() {

		// check permissions again and make sure we have what we need
		check_ajax_referer( 'lafka-foodmenu-cat-ordering', 'security' );
		if ( ! current_user_can( 'manage_categories' ) || empty( $_POST['id'] ) ) {
			wp_die( - 1 );
		}

		$taxonomy = 'lafka_foodmenu_category';

		$id      = (int) $_POST['id'];
		$next_id = isset( $_POST['nextid'] ) && (int) $_POST['nextid'] ? (int) $_POST['nextid'] : null;
		$term    = get_term_by( 'id', $id, $taxonomy );

		if ( ! $id || ! $term ) {
			wp_die( 0 );
		}

		lafka_reorder_terms( $term, $next_id, $taxonomy );

		$children = get_terms( $taxonomy, "child_of=$id&menu_order=ASC&hide_empty=0" );

		if ( $term && sizeof( $children ) ) {
			echo 'children';
			wp_die();
		}
	}
}

/**
 * Move a term before the a given element of its hierarchy level.
 *
 * @param int $the_term Term ID.
 * @param int $next_id The id of the next sibling element in save hierarchy level.
 * @param string $taxonomy Taxnomy.
 * @param int $index Term index (default: 0).
 * @param mixed $terms List of terms. (default: null).
 *
 * @return int
 */
if ( ! function_exists( 'lafka_reorder_terms' ) ) {
	function lafka_reorder_terms( $the_term, $next_id, $taxonomy, $index = 0, $terms = null ) {
		if ( ! $terms ) {
			$terms = get_terms( $taxonomy, 'menu_order=ASC&hide_empty=0&parent=0' );
		}
		if ( empty( $terms ) ) {
			return $index;
		}

		$id = intval( $the_term->term_id );

		$term_in_level = false; // Flag: is our term to order in this level of terms.

		foreach ( $terms as $term ) {
			$term_id = intval( $term->term_id );

			if ( $term_id === $id ) { // Our term to order, we skip.
				$term_in_level = true;
				continue; // Our term to order, we skip.
			}
			// the nextid of our term to order, lets move our term here.
			if ( null !== $next_id && $term_id === $next_id ) {
				$index ++;
				$index = lafka_set_term_order( $id, $index, $taxonomy, true );
			}

			// Set order.
			$index ++;
			$index = lafka_set_term_order( $term_id, $index, $taxonomy );

			// If that term has children we walk through them.
			$children = get_terms( $taxonomy, "parent={$term_id}&menu_order=ASC&hide_empty=0" );
			if ( ! empty( $children ) ) {
				$index = lafka_reorder_terms( $the_term, $next_id, $taxonomy, $index, $children );
			}
		}

		// No nextid meaning our term is in last position.
		if ( $term_in_level && null === $next_id ) {
			$index = lafka_set_term_order( $id, $index + 1, $taxonomy, true );
		}

		return $index;
	}
}

/**
 * Set the sort order of a term.
 *
 * @param int $term_id Term ID.
 * @param int $index Index.
 * @param string $taxonomy Taxonomy.
 * @param bool $recursive Recursive (default: false).
 *
 * @return int
 */
if ( ! function_exists( 'lafka_set_term_order' ) ) {
	function lafka_set_term_order( $term_id, $index, $taxonomy, $recursive = false ) {

		$term_id = (int) $term_id;
		$index   = (int) $index;

		// Meta name.
		$meta_name = 'order';

		update_term_meta( $term_id, $meta_name, $index );

		if ( ! $recursive ) {
			return $index;
		}

		$children = get_terms( $taxonomy, "parent=$term_id&menu_order=ASC&hide_empty=0" );

		foreach ( $children as $term ) {
			$index ++;
			$index = lafka_set_term_order( $term->term_id, $index, $taxonomy, true );
		}

		clean_term_cache( $term_id, $taxonomy );

		return $index;
	}
}

add_filter( 'terms_clauses', 'lafka_terms_clauses', 99, 3 );
if ( ! function_exists( 'lafka_terms_clauses' ) ) {
	function lafka_terms_clauses( $clauses, $taxonomies, $args ) {
		global $wpdb;
		require_wp_db();

		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( $taxonomy !== 'lafka_foodmenu_category' ) {
				return $clauses;
			}
		}

		// No sorting when orderby is non default.
		if ( isset( $args['orderby'] ) && 'name' !== $args['orderby'] ) {
			return $clauses;
		}

		// No sorting in admin when sorting by a column.
		if ( is_admin() && isset( $_GET['orderby'] ) ) { // WPCS: input var ok, CSRF ok.
			return $clauses;
		}

		// No need to filter counts.
		if ( strpos( 'COUNT(*)', $clauses['fields'] ) !== false ) {
			return $clauses;
		}

		// Query fields.
		$clauses['fields'] = $clauses['fields'] . ', tm.meta_value';
		$clauses['join']   .= " LEFT JOIN {$wpdb->termmeta} AS tm ON (t.term_id = tm.term_id AND tm.meta_key = 'order') ";
		$order             = 'ORDER BY tm.meta_value+0 ASC';

		if ( $clauses['orderby'] ) {
			$clauses['orderby'] = str_replace( 'ORDER BY', $order . ',', $clauses['orderby'] );
		} else {
			$clauses['orderby'] = $order;
		}

		// Grouping.
		if ( strstr( $clauses['fields'], 'tr.object_id' ) ) {
			$clauses['orderby'] = ' GROUP BY t.term_id, tr.object_id ' . $clauses['orderby'];
		} else {
			$clauses['orderby'] = ' GROUP BY t.term_id ' . $clauses['orderby'];
		}

		return $clauses;
	}
}

add_filter( 'posts_clauses', 'lafka_order_foodmenu_by_tax', 10, 2 );
if ( ! function_exists( 'lafka_order_foodmenu_by_tax' ) ) {
	/**
	 * Adding the default sorting for menu entries.
	 * Sorts by the category order field ASC and the post_date DESC
	 *
	 * @param $clauses
	 * @param $wp_query
	 *
	 * @return mixed
	 */
	function lafka_order_foodmenu_by_tax( $clauses, $wp_query ) {
		global $wpdb;

		if ( isset( $wp_query->query['post_type'] ) && $wp_query->query['post_type'] == 'lafka-foodmenu' && ! isset( $wp_query->query['orderby'] ) ) {
			$clauses['join']    .= " LEFT JOIN (
			SELECT object_id, GROUP_CONCAT(meta_value ORDER BY meta_value ASC) AS lafka_foodmenu_category
			FROM $wpdb->term_relationships
			INNER JOIN $wpdb->term_taxonomy USING (term_taxonomy_id)
			INNER JOIN $wpdb->terms USING (term_id)
			INNER JOIN $wpdb->termmeta USING (term_id)
			WHERE taxonomy = 'lafka_foodmenu_category' AND meta_key = 'order'
			GROUP BY object_id
		) AS foodmenu_category ON ($wpdb->posts.ID = foodmenu_category.object_id)";
			$clauses['orderby'] = 'foodmenu_category.lafka_foodmenu_category ASC, ' . $clauses['orderby'];
		}

		return $clauses;
	}
}