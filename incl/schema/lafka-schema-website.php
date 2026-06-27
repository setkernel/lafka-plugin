<?php
/**
 * WebSite JSON-LD node.
 *
 * Adds a site-wide WebSite entity to the @graph: it gives Google the canonical
 * site name (helps suppress an auto-generated one) and a SearchAction, which is
 * the signal for the sitelinks search box in search results. When the Restaurant
 * entity is configured, the WebSite is linked to it as `publisher` so the two
 * form one connected knowledge graph.
 *
 * @package Lafka\Plugin\Schema
 * @since   9.34.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_schema_website' ) ) {
	/**
	 * @return array<string,mixed>|null
	 */
	function lafka_schema_website(): ?array {
		if ( ! function_exists( 'home_url' ) ) {
			return null;
		}
		$home = function_exists( 'trailingslashit' ) ? trailingslashit( home_url( '/' ) ) : home_url( '/' );
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$node = array(
			'@type' => 'WebSite',
			'@id'   => $home . '#website',
			'url'   => $home,
		);
		if ( '' !== $name ) {
			$node['name'] = $name;
		}
		$desc = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '';
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		// Link to the Restaurant entity when its basics are configured.
		$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
		if ( ! empty( $info['name'] ) ) {
			$node['publisher'] = array( '@id' => $home . '#restaurant' );
		}

		// Sitelinks search box (WordPress native ?s= search).
		$node['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $home . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		);

		if ( function_exists( 'apply_filters' ) ) {
			$node = (array) apply_filters( 'lafka_schema_website', $node );
		}
		return $node;
	}
}
