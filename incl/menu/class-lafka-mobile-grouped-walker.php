<?php
/**
 * P6-UX-6 W3-T10: groups WC product categories into logical clusters
 * (Pizzas / Mains / Sides / Combos & Kids / Desserts / Drinks) for the
 * mobile drawer. The theme calls LafkaMobileGroupedWalker::group_terms()
 * with its category terms. (The class keeps its historical name; the
 * nav-menu walker half was never wired to a rendered menu and was removed
 * in 10.1.0.)
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

	class LafkaMobileGroupedWalker {

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
		 * Group → slug-list mapping for this render. Filtered.
		 *
		 * @var array<string, string[]>
		 */
		private $groups;

		/**
		 * Cache of slug → group name lookups so glob-matching only
		 * runs once per slug.
		 *
		 * @var array<string, string>
		 */
		private $slug_to_group_cache = array();

		public function __construct() {
			$this->groups = apply_filters( 'lafka_mobile_menu_groups', self::default_groups() );
		}

		/**
		 * Group a flat list of product-category terms into the heuristic
		 * clusters. The bundled lafka-theme mobile drawer calls this for its
		 * "Categories" section when the `lafka_mobile_menu_grouping` toggle
		 * is on.
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

		/**
		 * Resolve a slug to its group label.
		 *
		 * @param string $slug Lowercased category slug.
		 * @return string Group label, or "Everything else" if unmatched.
		 */
		private function resolve_group( $slug ) {
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
		private function slug_matches( $slug, $pattern ) {
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
