<?php
/**
 * GX3: <title> templates, per-post title override and the template-built
 * fallback meta descriptions for product and menu-category pages.
 *
 * Titles: WordPress' default "Page – Site" says nothing about what or where
 * a restaurant is. Each page type now renders an operator-editable template
 * (WooCommerce → Settings → Restaurant → Search & AI), e.g. the category
 * default "{term}[ in {city}]{sep}{name}" → "Poutine in Springfield – Acme
 * Kitchen". A per-post "SEO title" (the SEO meta box) wins over the template.
 *
 * Descriptions: a product without a short description, or a category
 * without a description, used to fall through to the same site-wide pitch on
 * dozens of pages. They now get a unique, fact-built line from the product /
 * category template ({price_from}, {count} …) — see
 * lafka_resolve_meta_description() in lafka-head-meta.php.
 *
 * Silent when Yoast / Rank Math / SEOPress / AIOSEO owns the head (the same
 * lafka_seo_plugin_active() gate every head emitter uses; the
 * `lafka_head_meta_force_emit` override applies) or when
 * `lafka_seo_titles_enabled` returns false.
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_seo_head_is_ours' ) ) {
	/**
	 * Whether Lafka owns head metadata on this request (no SEO plugin, or
	 * forced via `lafka_head_meta_force_emit`).
	 *
	 * @return bool
	 */
	function lafka_seo_head_is_ours(): bool {
		$plugin = function_exists( 'lafka_seo_plugin_active' ) && lafka_seo_plugin_active();
		return ! $plugin || (bool) apply_filters( 'lafka_head_meta_force_emit', false );
	}
}

if ( ! function_exists( 'lafka_seo_product_tokens' ) ) {
	/**
	 * Template tokens for a product.
	 *
	 * @param object $product WC_Product.
	 * @return array<string,string>
	 */
	function lafka_seo_product_tokens( $product ): array {
		return array(
			'product'    => is_object( $product ) && method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			'price_from' => lafka_seo_price_from_product( $product ),
		);
	}
}

if ( ! function_exists( 'lafka_seo_resolve_title' ) ) {
	/**
	 * The templated document title for the current request, or '' to leave
	 * WordPress' default in place (search, 404, archives we don't template).
	 *
	 * @return string Plain text (unescaped).
	 */
	function lafka_seo_resolve_title(): string {
		if ( ( function_exists( 'is_404' ) && is_404() ) || ( function_exists( 'is_search' ) && is_search() ) ) {
			return '';
		}

		$queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		$tokens  = array();
		$tpl     = '';

		// Per-post override (singular, incl. a static front page).
		if ( is_singular() && is_object( $queried ) && isset( $queried->ID ) ) {
			$tokens['title'] = (string) ( $queried->post_title ?? '' );
			$override        = trim( (string) get_post_meta( (int) $queried->ID, '_lafka_seo_title', true ) );
			if ( '' !== $override ) {
				$tpl = $override;
			}
		}

		$is_product = function_exists( 'is_product' ) && is_product();
		if ( $is_product && function_exists( 'wc_get_product' ) && is_object( $queried ) && isset( $queried->ID ) ) {
			$tokens += lafka_seo_product_tokens( wc_get_product( (int) $queried->ID ) );
		}

		$is_term = ( function_exists( 'is_product_category' ) && is_product_category() )
			|| ( function_exists( 'is_product_tag' ) && is_product_tag() );
		if ( $is_term && is_object( $queried ) ) {
			$tokens += lafka_seo_term_tokens( $queried );
		}

		if ( '' === $tpl ) {
			if ( is_front_page() ) {
				$tpl = lafka_seo_get( 'lafka_seo_title_home' );
			} elseif ( $is_product ) {
				$tpl = lafka_seo_get( 'lafka_seo_title_product' );
			} elseif ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'lafka_schema_is_menu_page' ) && lafka_schema_is_menu_page() ) ) {
				$tpl = lafka_seo_get( 'lafka_seo_title_menu' );
			} elseif ( $is_term ) {
				$tpl = lafka_seo_get( 'lafka_seo_title_category' );
			} elseif ( is_singular() ) {
				$tpl = lafka_seo_get( 'lafka_seo_title_page' );
			}
		}
		if ( '' === $tpl ) {
			return '';
		}

		$title = lafka_seo_render_template( $tpl, $tokens );

		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( '' !== $title && $paged > 1 ) {
			$base  = lafka_seo_base_tokens();
			/* translators: %s: page number of a paginated archive. */
			$title .= $base['sep'] . sprintf( __( 'Page %s', 'lafka-plugin' ), number_format_i18n( $paged ) );
		}

		/**
		 * Filter the templated document title (plain text).
		 *
		 * @since 10.2.0
		 * @param string               $title  Rendered title.
		 * @param string               $tpl    Template used.
		 * @param array<string,string> $tokens Page-specific tokens.
		 */
		return trim( (string) apply_filters( 'lafka_seo_document_title', $title, $tpl, $tokens ) );
	}
}

if ( ! function_exists( 'lafka_seo_document_title' ) ) {
	/**
	 * `pre_get_document_title` callback. A non-empty return short-circuits
	 * wp_get_document_title(), which then prints it unescaped — so escape here.
	 *
	 * @param string $title Title from an earlier filter ('' by default).
	 * @return string
	 */
	function lafka_seo_document_title( $title ) {
		if ( '' !== (string) $title || is_admin() || is_feed() ) {
			return $title;
		}
		if ( ! lafka_seo_head_is_ours() || ! (bool) apply_filters( 'lafka_seo_titles_enabled', true ) ) {
			return $title;
		}
		$resolved = lafka_seo_resolve_title();
		return '' === $resolved ? $title : esc_html( $resolved );
	}
}

if ( ! function_exists( 'lafka_seo_product_description' ) ) {
	/**
	 * Fact-built fallback meta description for a product without a short
	 * description (product template).
	 *
	 * @param object $product WC_Product.
	 * @return string
	 */
	function lafka_seo_product_description( $product ): string {
		if ( ! is_object( $product ) ) {
			return '';
		}
		return lafka_seo_excerpt( lafka_seo_render_template( lafka_seo_get( 'lafka_seo_desc_product' ), lafka_seo_product_tokens( $product ) ) );
	}
}

if ( ! function_exists( 'lafka_seo_term_description' ) ) {
	/**
	 * Fallback meta description for a product category / tag without a
	 * description (category template).
	 *
	 * @param object $term WP_Term.
	 * @return string
	 */
	function lafka_seo_term_description( $term ): string {
		if ( ! is_object( $term ) ) {
			return '';
		}
		return lafka_seo_excerpt( lafka_seo_render_template( lafka_seo_get( 'lafka_seo_desc_category' ), lafka_seo_term_tokens( $term ) ) );
	}
}

add_filter( 'pre_get_document_title', 'lafka_seo_document_title', 20 );
