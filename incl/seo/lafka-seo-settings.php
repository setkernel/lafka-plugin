<?php
/**
 * GX3: Search & AI visibility settings — defaults, accessors and the
 * title/description template engine.
 *
 * Every option here is edited under WooCommerce → Settings → Restaurant →
 * "Search & AI" (incl/admin/class-lafka-wc-settings-restaurant.php) and has a
 * working default, so an install that never opens that screen still ships
 * locality-aware titles, per-page descriptions, /llms.txt and friends.
 *
 * Template syntax (titles and descriptions):
 *   - `{token}` is replaced by its value: {name} {city} {region} {cuisines}
 *     {term} {product} {short} {title} {price_from} {count} {phone} {sep}.
 *   - `[ … ]` marks an optional segment, dropped entirely when any token
 *     inside it is empty — so "{term}[ in {city}]" never renders a dangling
 *     " in " on an install without a city.
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_seo_plain_defaults' ) ) {
	/**
	 * Defaults that need no translation (toggles, separators, maps) — kept
	 * apart so reading a toggle never loads the translatable templates.
	 *
	 * @return array<string,string>
	 */
	function lafka_seo_plain_defaults(): array {
		return array(
			'lafka_seo_title_sep'           => '–',
			'lafka_seo_menu_schema_on_home' => 'no',
			'lafka_seo_diet_map'            => '',
			'lafka_seo_llms_enabled'        => 'yes',
			'lafka_seo_indexnow_enabled'    => 'no',
		);
	}
}

if ( ! function_exists( 'lafka_seo_defaults' ) ) {
	/**
	 * Default value of every Search & AI option.
	 *
	 * @return array<string,string>
	 */
	function lafka_seo_defaults(): array {
		$defaults = lafka_seo_plain_defaults() + array(
			/* translators: SEO title template for the home page. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_title_home'          => __( '{name}[{sep}{cuisines}][ in {city}]{sep}Order Online', 'lafka-plugin' ),
			/* translators: SEO title template for the menu page. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_title_menu'          => __( 'Menu[{sep}{cuisines} in {city}]{sep}{name}', 'lafka-plugin' ),
			/* translators: SEO title template for menu category pages. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_title_category'      => __( '{term} Menu[ in {city}]{sep}{name}', 'lafka-plugin' ),
			/* translators: SEO title template for product pages. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_title_product'       => __( '{product}{sep}{name}', 'lafka-plugin' ),
			/* translators: SEO title template for regular pages and posts. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_title_page'          => __( '{title}{sep}{name}', 'lafka-plugin' ),
			/* translators: fallback meta description for menu category pages. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_desc_category'       => __( 'Order {term} online from {name}[ in {city}][ — {count} items from {price_from}].', 'lafka-plugin' ),
			/* translators: fallback meta description for product pages. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_desc_product'        => __( '{product}[ — {short}][, from {price_from}] at {name}[ in {city}]. Order online[ or call {phone}].', 'lafka-plugin' ),
			/* translators: fallback meta description for the menu page. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_desc_menu'           => __( 'The full {name} menu[ — {cuisines}][ in {city}][, from {price_from}]. Order online[ or call {phone}].', 'lafka-plugin' ),
			/* translators: fallback meta description for pages without their own. Keep the {tokens} and [optional] brackets. */
			'lafka_seo_desc_page'           => __( '{title} — {name}[ in {city}]. Order online[ or call {phone}].', 'lafka-plugin' ),
		);

		/**
		 * Filter the Search & AI option defaults.
		 *
		 * @since 10.2.0
		 * @param array<string,string> $defaults Option name => default.
		 */
		return (array) apply_filters( 'lafka_seo_defaults', $defaults );
	}
}

if ( ! function_exists( 'lafka_seo_get' ) ) {
	/**
	 * Read a Search & AI option, falling back to its default when unset or
	 * blank (clearing a template field restores the default template).
	 *
	 * @param string $name Option name (lafka_seo_*).
	 * @return string
	 */
	function lafka_seo_get( string $name ): string {
		$value = get_option( $name, null );
		if ( null === $value || false === $value || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			$plain    = lafka_seo_plain_defaults();
			$defaults = array_key_exists( $name, $plain ) ? apply_filters( 'lafka_seo_defaults', $plain ) : lafka_seo_defaults();
			$value    = (string) ( $defaults[ $name ] ?? '' );
		} else {
			$value = (string) $value;
		}

		/**
		 * Filter a resolved Search & AI option value.
		 *
		 * @since 10.2.0
		 * @param string $value Resolved value.
		 * @param string $name  Option name.
		 */
		return (string) apply_filters( 'lafka_seo_option', $value, $name );
	}
}

if ( ! function_exists( 'lafka_seo_is_on' ) ) {
	/**
	 * Whether a yes/no Search & AI toggle is on.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	function lafka_seo_is_on( string $name ): bool {
		return in_array( strtolower( lafka_seo_get( $name ) ), array( 'yes', '1', 'on', 'true' ), true );
	}
}

if ( ! function_exists( 'lafka_seo_format_price' ) ) {
	/**
	 * Plain-text price ("$12.50") in the store currency, for titles,
	 * descriptions and llms.txt (never HTML).
	 *
	 * @param float|string $amount Amount.
	 * @return string '' for a non-numeric amount.
	 */
	function lafka_seo_format_price( $amount ): string {
		if ( ! is_numeric( $amount ) ) {
			return '';
		}
		if ( function_exists( 'wc_price' ) ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
			return trim( str_replace( "\xC2\xA0", ' ', $text ) );
		}
		return number_format( (float) $amount, 2, '.', '' );
	}
}

if ( ! function_exists( 'lafka_seo_base_tokens' ) ) {
	/**
	 * Site-wide template tokens, from the canonical business record.
	 *
	 * @return array<string,string>
	 */
	function lafka_seo_base_tokens(): array {
		$info     = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
		$cuisines = isset( $info['cuisines'] ) && is_array( $info['cuisines'] ) ? array_values( $info['cuisines'] ) : array();
		/**
		 * How many cuisines the {cuisines} token lists (titles stay short).
		 *
		 * @since 10.2.0
		 * @param int $limit Default 2.
		 */
		$limit    = max( 1, (int) apply_filters( 'lafka_seo_cuisines_token_limit', 2 ) );
		$cuisines = array_slice( $cuisines, 0, $limit );
		$last     = array_pop( $cuisines );
		$joined   = empty( $cuisines ) ? (string) $last : implode( ', ', $cuisines ) . ' & ' . $last;

		$name = (string) ( $info['name'] ?? '' );
		if ( '' === $name && function_exists( 'get_bloginfo' ) ) {
			$name = (string) get_bloginfo( 'name' );
		}

		return array(
			'name'     => $name,
			'city'     => (string) ( $info['city'] ?? '' ),
			'region'   => (string) ( $info['region'] ?? '' ),
			'cuisines' => $joined,
			'phone'    => (string) ( $info['phone_display'] ?? '' ),
			'sep'      => ' ' . trim( lafka_seo_get( 'lafka_seo_title_sep' ) ) . ' ',
		);
	}
}

if ( ! function_exists( 'lafka_seo_render_template' ) ) {
	/**
	 * Render a title/description template.
	 *
	 * @param string               $template Template with {tokens} and [optional] segments.
	 * @param array<string,string> $tokens   Token => value (merged over the base tokens).
	 * @return string Plain text, whitespace-collapsed, no dangling separators.
	 */
	function lafka_seo_render_template( string $template, array $tokens = array() ): string {
		$tokens = array_merge( lafka_seo_base_tokens(), array_map( 'strval', $tokens ) );

		$value_of = static function ( string $token ) use ( $tokens ): string {
			return isset( $tokens[ $token ] ) ? trim( (string) $tokens[ $token ] ) : '';
		};

		// Optional segments first: keep "[…]" only when every token inside resolves.
		$template = (string) preg_replace_callback(
			'/\[([^\[\]]*)\]/',
			static function ( $m ) use ( $value_of ) {
				if ( preg_match_all( '/\{([a-z0-9_]+)\}/', $m[1], $found ) ) {
					foreach ( $found[1] as $token ) {
						if ( 'sep' !== $token && '' === $value_of( $token ) ) {
							return '';
						}
					}
				}
				return $m[1];
			},
			$template
		);

		$out = (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static function ( $m ) use ( $tokens, $value_of ) {
				// {sep} keeps its surrounding spaces; every other token is trimmed.
				return 'sep' === $m[1] ? (string) ( $tokens['sep'] ?? ' ' ) : $value_of( $m[1] );
			},
			$template
		);

		// Tidy: collapse whitespace, drop doubled / dangling separators left by
		// an empty token outside an optional segment.
		$sep = trim( (string) ( $tokens['sep'] ?? '' ) );
		$out = (string) preg_replace( '/\s+/u', ' ', $out );
		if ( '' !== $sep ) {
			$q   = preg_quote( $sep, '/' );
			$out = (string) preg_replace( '/(?:\s*' . $q . '\s*){2,}/u', ' ' . $sep . ' ', $out );
			$out = (string) preg_replace( '/^\s*' . $q . '\s*|\s*' . $q . '\s*$/u', '', $out );
		}
		$out = (string) preg_replace( '/\s+([.,])/u', '$1', $out );
		return trim( $out );
	}
}

if ( ! function_exists( 'lafka_seo_excerpt' ) ) {
	/**
	 * Plain-text excerpt capped at a word boundary (meta descriptions).
	 *
	 * @param string $text  Source (HTML allowed; stripped).
	 * @param int    $limit Max characters (default 160, the SERP snippet bound).
	 * @return string
	 */
	function lafka_seo_excerpt( string $text, int $limit = 160 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( '' === $text || mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > (int) ( $limit * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t,.;:–—-" ) . '…';
	}
}

if ( ! function_exists( 'lafka_seo_price_from_product' ) ) {
	/**
	 * Lowest display price of a product ('' when unpriced).
	 *
	 * @param object $product WC_Product.
	 * @return string Formatted plain-text price.
	 */
	function lafka_seo_price_from_product( $product ): string {
		if ( ! is_object( $product ) ) {
			return '';
		}
		$low = '';
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
			$prices = $product->get_variation_prices( true );
			if ( ! empty( $prices['price'] ) ) {
				$low = min( $prices['price'] );
			}
		}
		if ( '' === $low && method_exists( $product, 'get_price' ) ) {
			$low = $product->get_price();
		}
		return ( '' === $low || null === $low ) ? '' : lafka_seo_format_price( $low );
	}
}

if ( ! function_exists( 'lafka_seo_term_tokens' ) ) {
	/**
	 * Template tokens for a product category / tag archive: {term}, {count}
	 * and {price_from} (lowest price in the section, read from the cached
	 * menu data the Menu JSON-LD is built from — no extra product query).
	 *
	 * @param object $term WP_Term.
	 * @return array<string,string>
	 */
	function lafka_seo_term_tokens( $term ): array {
		$tokens = array(
			'term'       => isset( $term->name ) ? (string) $term->name : '',
			'count'      => isset( $term->count ) && (int) $term->count > 0 ? (string) (int) $term->count : '',
			'price_from' => '',
		);
		if ( isset( $term->term_id ) && function_exists( 'lafka_schema_menu_data' ) ) {
			$data = lafka_schema_menu_data();
			$sec  = $data['sections'][ (int) $term->term_id ] ?? null;
			if ( is_array( $sec ) && isset( $sec['price_min'] ) && '' !== $sec['price_min'] ) {
				$tokens['price_from'] = lafka_seo_format_price( $sec['price_min'] );
			}
		}
		return $tokens;
	}
}
