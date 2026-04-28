<?php
/**
 * P6-SEO-1/2/3/6: JSON-LD structured data emitter.
 *
 * Registers a wp_head callback at priority 11 (after the meta description
 * from W1-T15 at priority 1). Picks generators based on page context:
 *
 *  - Restaurant (LocalBusiness + FoodEstablishment): sitewide, every page.
 *  - Product: single WooCommerce product pages only.
 *  - Menu: /menu/, product-category archives, shop page.
 *  - BreadcrumbList: every page except the homepage.
 *
 * All output is wrapped in an @graph array per Google's recommended
 * multi-type pattern. JSON encoded via wp_json_encode (handles UTF-8 and
 * ensures the "&" in "Peppery Pizza & Poutine" is not HTML-escaped in JSON-LD).
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lafka-schema-helpers.php';
require_once __DIR__ . '/lafka-schema-restaurant.php';
require_once __DIR__ . '/lafka-schema-menu.php';
require_once __DIR__ . '/lafka-schema-product.php';
require_once __DIR__ . '/lafka-schema-breadcrumb.php';

if ( ! class_exists( 'Lafka_JSON_LD' ) ) {

	/**
	 * Orchestrates JSON-LD structured data emission on wp_head.
	 *
	 * @since 8.8.1
	 */
	final class Lafka_JSON_LD {

		/**
		 * Register the wp_head callback.
		 *
		 * Called once at file include time. The emit() method is a pure static
		 * callback so no instance is stored.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_head', array( __CLASS__, 'emit' ), 11 );
		}

		/**
		 * Build and echo the JSON-LD <script> block.
		 *
		 * Runs at wp_head priority 11. Skips admin screens, feeds, and 404 pages.
		 *
		 * @return void
		 */
		public static function emit(): void {
			if ( is_admin() || is_feed() || is_404() ) {
				return;
			}

			$graph = array();

			// Restaurant schema — emitted on every public page.
			$graph[] = lafka_schema_restaurant();

			// Single product page.
			if ( function_exists( 'is_product' ) && is_product() ) {
				$graph[] = lafka_schema_product();
			}

			// Menu context: /menu/, shop, product-category archives.
			if ( lafka_schema_is_menu_context() ) {
				$graph[] = lafka_schema_menu();
			}

			// BreadcrumbList — every page except the homepage.
			if ( ! is_front_page() ) {
				$graph[] = lafka_schema_breadcrumb();
			}

			/**
			 * Filter the full @graph array before emission.
			 *
			 * Use this to add, remove, or reorder schema blocks sitewide.
			 *
			 * @since 8.8.1
			 * @param array<int, array<string, mixed>> $graph Array of schema node arrays.
			 */
			$graph = apply_filters( 'lafka_json_ld_graph', array_values( array_filter( $graph ) ) );

			if ( empty( $graph ) ) {
				return;
			}

			$payload = array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			);

			// JSON_UNESCAPED_SLASHES: URLs render as-is, not with \/ escaping.
			// Not pretty-printed in production — saves ~20 % bytes.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<script type=\"application/ld+json\">"
				. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES )
				. "</script>\n";
		}
	}

	Lafka_JSON_LD::init();
}
