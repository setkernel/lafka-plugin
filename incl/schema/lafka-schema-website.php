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

if ( ! function_exists( 'lafka_schema_has_restaurant_basics' ) ) {
	/**
	 * Whether the operator has configured the minimum NAP basics that gate
	 * Restaurant (#restaurant) JSON-LD emission.
	 *
	 * The Restaurant node is added to the @graph (by Lafka_JSON_LD::emit()) only
	 * when name, street, city, postal, and phone (E.164) are ALL set. Any node
	 * that links to the #restaurant @id — e.g. WebSite.publisher — MUST gate on
	 * this same predicate so the reference can never dangle to a node that is
	 * absent from the graph (which the Rich Results validator flags). Factoring
	 * the check here keeps the two gates from diverging.
	 *
	 * @since 9.35.1
	 *
	 * @return bool True when every required NAP basic is populated.
	 */
	function lafka_schema_has_restaurant_basics(): bool {
		$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
		return ! empty( $info['name'] )
			&& ! empty( $info['street'] )
			&& ! empty( $info['city'] )
			&& ! empty( $info['postal'] )
			&& ! empty( $info['phone_e164'] );
	}
}

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

		// Link to the Restaurant entity only when its full NAP basics are
		// configured — i.e. only when Lafka_JSON_LD::emit() will actually add the
		// #restaurant node to the @graph. Gating on the shared predicate keeps the
		// publisher @id from dangling to a node absent from the graph (the OSS
		// default and most fresh installs lack full NAP), which the Rich Results
		// validator flags as an unresolved reference.
		if ( lafka_schema_has_restaurant_basics() ) {
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
