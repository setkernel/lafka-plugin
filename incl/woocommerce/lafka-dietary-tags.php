<?php
/**
 * Dietary tag seeder.
 *
 * The menu archive ships a dietary-filter chip row (Popular / Vegetarian /
 * Vegan / Spicy) wired to four product_tag slugs. If those terms aren't
 * present in the WC product_tag taxonomy, the chip UI renders but matches
 * zero products — the filter looks broken to customers.
 *
 * This module guarantees the four canonical terms exist on every install
 * (idempotent seeder + WP-CLI command for explicit re-runs). Operators
 * remain free to add additional product_tag terms — only the four
 * filter-backing slugs are managed here.
 *
 * Filter hooks:
 *  - lafka_dietary_tags_seed  — alter the [slug => [name, description]] map.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_dietary_tags_canonical' ) ) {
	/**
	 * Canonical filter-backing dietary tags. Slugs are contractual — they
	 * appear in menu-controls.php as the data-filter values. Names &
	 * descriptions are translatable and filterable.
	 *
	 * @return array<string, array{name: string, description: string}>
	 */
	function lafka_dietary_tags_canonical(): array {
		$tags = array(
			'popular'    => array(
				'name'        => __( 'Popular', 'lafka' ),
				'description' => __( 'Customer favourites — bestselling items.', 'lafka' ),
			),
			'vegetarian' => array(
				'name'        => __( 'Vegetarian', 'lafka' ),
				'description' => __( 'No meat or seafood. May contain dairy or eggs.', 'lafka' ),
			),
			'vegan'      => array(
				'name'        => __( 'Vegan', 'lafka' ),
				'description' => __( 'No animal products of any kind.', 'lafka' ),
			),
			'spicy'      => array(
				'name'        => __( 'Spicy', 'lafka' ),
				'description' => __( 'Contains chilli or other heat sources.', 'lafka' ),
			),
		);
		return (array) apply_filters( 'lafka_dietary_tags_seed', $tags );
	}
}

if ( ! function_exists( 'lafka_dietary_tags_seed' ) ) {
	/**
	 * Ensure the canonical filter-backing tags exist. Safe to call any
	 * number of times — only creates missing terms, never overwrites
	 * existing ones.
	 *
	 * @return array<string, int> Map of slug => term_id for every canonical tag.
	 */
	function lafka_dietary_tags_seed(): array {
		$result = array();
		if ( ! taxonomy_exists( 'product_tag' ) ) {
			return $result;
		}
		foreach ( lafka_dietary_tags_canonical() as $slug => $meta ) {
			$existing = get_term_by( 'slug', $slug, 'product_tag' );
			if ( $existing instanceof WP_Term ) {
				$result[ $slug ] = (int) $existing->term_id;
				continue;
			}
			$inserted = wp_insert_term(
				$meta['name'],
				'product_tag',
				array(
					'slug'        => $slug,
					'description' => $meta['description'],
				)
			);
			if ( is_array( $inserted ) && isset( $inserted['term_id'] ) ) {
				$result[ $slug ] = (int) $inserted['term_id'];
			}
		}
		update_option( 'lafka_dietary_tags_seeded_version', '9.13.0', false );
		return $result;
	}
}

if ( ! function_exists( 'lafka_dietary_tags_maybe_seed' ) ) {
	/**
	 * Lazy idempotent seeder. Runs once when the option version doesn't
	 * match the current code version; subsequent loads short-circuit.
	 */
	function lafka_dietary_tags_maybe_seed(): void {
		if ( '9.13.0' === get_option( 'lafka_dietary_tags_seeded_version', '' ) ) {
			return;
		}
		if ( ! taxonomy_exists( 'product_tag' ) ) {
			return;
		}
		lafka_dietary_tags_seed();
	}
}

// Seed on WC init so product_tag is registered before we try to write.
add_action( 'woocommerce_init', 'lafka_dietary_tags_maybe_seed', 20 );

// Also seed on plugin activation for fresh installs.
if ( defined( 'LAFKA_PLUGIN_FILE' ) ) {
	register_activation_hook( LAFKA_PLUGIN_FILE, 'lafka_dietary_tags_seed' );
}

// WP-CLI command for explicit re-runs.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'lafka dietary-tags seed',
		function () {
			$result = lafka_dietary_tags_seed();
			if ( empty( $result ) ) {
				WP_CLI::error( 'Could not seed dietary tags — is WooCommerce active?' );
			}
			WP_CLI::success(
				sprintf(
					'Seeded %d dietary tags: %s',
					count( $result ),
					implode( ', ', array_keys( $result ) )
				)
			);
		},
		array(
			'shortdesc' => 'Seed canonical dietary filter tags (popular, vegetarian, vegan, spicy).',
		)
	);
}
