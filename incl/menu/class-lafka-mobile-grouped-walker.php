<?php
/**
 * P6-UX-6 W3-T10: mobile menu walker that groups WC product categories
 * into logical clusters (Pizzas / Mains / Sides / Combos & Kids /
 * Desserts / Drinks).
 *
 * Default category-slug heuristic (English, OSS-safe — works for any
 * restaurant whose categories follow common naming patterns):
 *
 *   pizza*                                                      → PIZZAS
 *   donair, burger, sub, calzone, wrap, fish*chips, wing, chicken   → MAINS
 *   garlic*finger, poutine, sauce, appetizer, nacho                  → SIDES
 *   combo, kid                                                       → COMBOS & KIDS
 *   dessert                                                          → DESSERTS
 *   drink, beer, wine, cooler, soft*drink                            → DRINKS
 *
 * Operator overrides via the `lafka_mobile_menu_groups` filter:
 *
 *   add_filter( 'lafka_mobile_menu_groups', function ( $groups ) {
 *       $groups['Mains'][] = 'noodles';            // add a slug to a group
 *       $groups['Snacks'] = array( 'chips', 'fries' );  // add a new group
 *       unset( $groups['Pizzas'] );                // remove a group
 *       return $groups;
 *   } );
 *
 * Slugs not matching any group fall through to a final "Everything else"
 * bucket so nothing disappears from the menu.
 *
 * Activation: Customizer toggle `lafka_mobile_menu_grouping` (yes/no).
 * Default: no (flat menu). The operator opts in.
 *
 * @package Lafka\Plugin
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LafkaMobileGroupedWalker' ) ) {

	class LafkaMobileGroupedWalker extends Walker_Nav_Menu {

		/**
		 * Default heuristic group → slug-pattern map.
		 * Each entry is `pattern (lowercase, * is glob)` → group label.
		 * Matching is on lowercased slug; first matching group wins.
		 *
		 * @return array<string, string[]>
		 */
		public static function default_groups() {
			return array(
				'Pizzas'        => array( 'pizza', 'pizza-*', '*-pizza', '*-pies', 'pies' ),
				'Mains'         => array( 'donair', 'burger', 'burgers', 'sub', 'subs', 'oven-toasted-subs', 'calzone', 'calzones', 'wrap', 'wraps', 'fish-and-chips', 'homemade-fish-and-chips', 'wing', 'wings', 'chicken' ),
				'Sides'         => array( 'garlic-finger', 'garlic-fingers', 'poutine', 'sauce', 'sauces', 'appetizer', 'appetizers', 'nacho', 'nachos' ),
				'Combos & Kids' => array( 'combo', 'combos', 'kids', 'kids-menu' ),
				'Desserts'      => array( 'dessert', 'desserts' ),
				'Drinks'        => array( 'drink', 'drinks', 'beer', 'beers', 'beer-and-coolers', 'wine', 'wines', 'cooler', 'coolers', 'soft-drink', 'soft-drinks' ),
			);
		}

		/**
		 * Tracks which group we're currently inside so we can emit
		 * group-end / new-group-start markers as items pass.
		 *
		 * @var string|null
		 */
		protected $current_group = null;

		/**
		 * Group → slug-list mapping for this render. Filtered.
		 *
		 * @var array<string, string[]>
		 */
		protected $groups;

		/**
		 * Cache of slug → group name lookups so glob-matching only
		 * runs once per slug.
		 *
		 * @var array<string, string>
		 */
		protected $slug_to_group_cache = array();

		public function __construct() {
			$this->groups = apply_filters( 'lafka_mobile_menu_groups', self::default_groups() );
		}

		/**
		 * Group a flat list of product-category terms into the heuristic
		 * clusters — the same resolve_group() mapping the nav walker uses.
		 *
		 * This is THE consumer path for the bundled lafka-theme mobile drawer:
		 * its "Categories" section builds from get_terms(), not wp_nav_menu(),
		 * so the Walker hooks below never fire for it. The drawer calls this
		 * when the `lafka_mobile_menu_grouping` toggle is on.
		 *
		 * @param array $terms WP_Term[] (any objects exposing ->slug).
		 * @return array<string, array> Ordered label => terms. Groups with no
		 *                              terms are omitted; unmatched terms land
		 *                              in a final "Everything else" bucket.
		 */
		public static function group_terms( array $terms ): array {
			$resolver = new self();
			$order    = array_keys( $resolver->groups );
			$order[]  = 'Everything else';

			$grouped = array_fill_keys( $order, array() );
			foreach ( $terms as $term ) {
				$slug = isset( $term->slug ) ? strtolower( (string) $term->slug ) : '';
				$grouped[ $resolver->resolve_group( $slug ) ][] = $term;
			}

			return array_filter( $grouped );
		}

		public function start_lvl( &$output, $depth = 0, $args = null ) {
			// Top-level groups don't have a sub-level <ul> wrapper here; we
			// emit our own group markers instead. Defer to default for child
			// levels (sub-categories within a category).
			if ( $depth >= 1 ) {
				parent::start_lvl( $output, $depth, $args );
			}
		}

		public function end_lvl( &$output, $depth = 0, $args = null ) {
			if ( $depth >= 1 ) {
				parent::end_lvl( $output, $depth, $args );
			}
		}

		public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
			if ( $depth === 0 ) {
				$slug  = $this->item_to_slug( $item );
				$group = $this->resolve_group( $slug );
				if ( $group !== $this->current_group ) {
					if ( $this->current_group !== null ) {
						$output .= '</ul></div>';
					}
					$output .= sprintf(
						'<div class="lafka-mobile-menu-group lafka-group-%s"><h4 class="lafka-mobile-menu-group-label">%s</h4><ul class="lafka-mobile-menu-group-items">',
						esc_attr( sanitize_title( $group ) ),
						esc_html( $group )
					);
					$this->current_group = $group;
				}
			}
			parent::start_el( $output, $item, $depth, $args, $id );
		}

		public function end_el( &$output, $item, $depth = 0, $args = null ) {
			parent::end_el( $output, $item, $depth, $args );
		}

		/**
		 * Close the last open group block. Call after walk() completes.
		 *
		 * @param string $output Passed by reference.
		 */
		public function emit_close_groups( &$output ) {
			if ( $this->current_group !== null ) {
				$output .= '</ul></div>';
				$this->current_group = null;
			}
		}

		/**
		 * Get a slug for the menu item — prefer associated term slug if it's
		 * a category nav item, fall back to a sanitised title.
		 *
		 * @param object $item Nav menu item object.
		 * @return string Lowercased slug.
		 */
		protected function item_to_slug( $item ) {
			$slug = '';
			if ( ! empty( $item->object_id ) ) {
				$term = get_term( (int) $item->object_id );
				if ( $term && ! is_wp_error( $term ) && ! empty( $term->slug ) ) {
					$slug = $term->slug;
				}
			}
			if ( $slug === '' && ! empty( $item->title ) ) {
				$slug = sanitize_title( $item->title );
			}
			return strtolower( $slug );
		}

		/**
		 * Resolve a slug to its group label.
		 *
		 * @param string $slug Lowercased category slug.
		 * @return string Group label, or "Everything else" if unmatched.
		 */
		protected function resolve_group( $slug ) {
			if ( isset( $this->slug_to_group_cache[ $slug ] ) ) {
				return $this->slug_to_group_cache[ $slug ];
			}
			$matched = 'Everything else';
			foreach ( $this->groups as $label => $patterns ) {
				foreach ( $patterns as $pattern ) {
					if ( $this->slug_matches( $slug, $pattern ) ) {
						$matched = $label;
						break 2;
					}
				}
			}
			$this->slug_to_group_cache[ $slug ] = $matched;
			return $matched;
		}

		/**
		 * Test whether a slug matches a pattern (exact, glob, or substring).
		 *
		 * @param string $slug    Lowercased slug.
		 * @param string $pattern Pattern (may contain * as wildcard).
		 * @return bool
		 */
		protected function slug_matches( $slug, $pattern ) {
			// Exact match.
			if ( $slug === $pattern ) {
				return true;
			}
			// Glob with leading/trailing *.
			if ( strpos( $pattern, '*' ) !== false ) {
				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
				return (bool) preg_match( $regex, $slug );
			}
			// Substring match: "pizza" matches "pizza-classic", "speciality-pizzas", etc.
			return false !== strpos( $slug, $pattern );
		}
	}
}

/**
 * Pre-sort mobile menu items by group so the walker can emit clean
 * non-interleaved group blocks. Only applies when grouping is enabled
 * and the menu location is "mobile".
 *
 * Hooks wp_nav_menu_objects (runs before any walker), so sorting is
 * done once without touching walker state.
 */
if ( ! function_exists( 'lafka_mobile_menu_sort_by_group' ) ) {
	add_filter( 'wp_nav_menu_objects', 'lafka_mobile_menu_sort_by_group', 10, 2 );
	function lafka_mobile_menu_sort_by_group( $items, $args ) {
		if ( empty( $args->theme_location ) || $args->theme_location !== 'mobile' ) {
			return $items;
		}
		if ( 'yes' !== get_theme_mod( 'lafka_mobile_menu_grouping', 'no' ) ) {
			return $items;
		}
		if ( ! class_exists( 'LafkaMobileGroupedWalker' ) ) {
			return $items;
		}

		// Build a temporary instance to access protected resolve_group / item_to_slug.
		$resolver     = new LafkaMobileGroupedWalker();
		$groups_order = array_keys( apply_filters( 'lafka_mobile_menu_groups', LafkaMobileGroupedWalker::default_groups() ) );
		$groups_order[] = 'Everything else';
		$group_index  = array_flip( $groups_order );

		// Annotate top-level items with their resolved group.
		foreach ( $items as $item ) {
			if ( (int) $item->menu_item_parent === 0 ) {
				$slug               = $resolver->item_to_slug( $item );
				$item->_lafka_group = $resolver->resolve_group( $slug );
			}
		}

		// Stable sort: group order first, then original menu_order within a group.
		usort(
            $items,
            function ( $a, $b ) use ( $group_index ) {
				$ag = $a->_lafka_group ?? 'Everything else';
				$bg = $b->_lafka_group ?? 'Everything else';
				$ai = $group_index[ $ag ] ?? PHP_INT_MAX;
				$bi = $group_index[ $bg ] ?? PHP_INT_MAX;
				if ( $ai !== $bi ) {
					return $ai - $bi;
				}
				return ( (int) $a->menu_order ) - ( (int) $b->menu_order );
			} 
        );

		return $items;
	}
}

/**
 * Override the mobile menu walker when the grouping toggle is on.
 *
 * Fires for any theme that renders a nav menu with theme_location 'mobile'
 * AND applies the `lafka_nav_menu_walker` filter (theme-agnostic contract).
 * NOTE: the bundled lafka-theme drawer (v5.55+ handoff header) does NOT
 * render a 'mobile' location — it consumes group_terms() directly for its
 * Categories section — so for that theme this filter is back-compat surface,
 * not the active path.
 */
if ( ! function_exists( 'lafka_mobile_menu_grouped_walker_filter' ) ) {
	add_filter( 'lafka_nav_menu_walker', 'lafka_mobile_menu_grouped_walker_filter', 10, 2 );
	function lafka_mobile_menu_grouped_walker_filter( $walker, $location ) {
		if ( $location !== 'mobile' ) {
			return $walker;
		}
		if ( 'yes' !== get_theme_mod( 'lafka_mobile_menu_grouping', 'no' ) ) {
			return $walker;
		}
		return new LafkaMobileGroupedWalker();
	}
}
