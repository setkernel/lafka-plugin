<?php
/**
 * P6-SEO-3: Product schema generator for WooCommerce single-product pages.
 *
 * Emitted only on is_product() pages. Builds a schema.org/Product with an
 * Offer (simple products) or AggregateOffer (variable products), plus an
 * AggregateRating block when WC reviews exist.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and return the Product schema array for the current queried product.
 *
 * @return array<string, mixed>|null  Null when not on a product page or WC absent.
 */
function lafka_schema_product(): ?array {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$product_id = (int) get_queried_object_id();
	if ( 0 === $product_id ) {
		return null;
	}

	$product = wc_get_product( $product_id );
	if ( ! ( $product instanceof WC_Product ) ) {
		return null;
	}

	$nap     = lafka_schema_get_nap();
	$url     = get_permalink( $product_id );
	$url     = $url ? (string) $url : '';

	$schema = array(
		'@type'  => 'Product',
		'@id'    => $url . '#product',
		'name'   => $product->get_name(),
		'url'    => $url,
		'brand'  => array(
			'@type' => 'Brand',
			'name'  => $nap['name'],
		),
	);

	// Short description.
	$short_desc = wp_strip_all_tags( $product->get_short_description() );
	if ( '' !== $short_desc ) {
		$schema['description'] = $short_desc;
	} elseif ( '' !== $product->get_description() ) {
		$schema['description'] = wp_trim_words(
			wp_strip_all_tags( $product->get_description() ),
			30,
			'...'
		);
	}

	// Featured image.
	$img_id = $product->get_image_id();
	if ( $img_id ) {
		$img_src = wp_get_attachment_image_url( (int) $img_id, 'woocommerce_single' );
		if ( $img_src ) {
			$schema['image'] = $img_src;
		}
	}

	// SKU.
	$sku = $product->get_sku();
	if ( '' !== $sku ) {
		$schema['sku'] = $sku;
	}

	// Offer / AggregateOffer.
	$offer = lafka_schema_build_product_offer( $product, $url );
	if ( null !== $offer ) {
		$schema['offers'] = $offer;
	}

	// AggregateRating — only when WC reviews are enabled and reviews exist.
	$rating = lafka_schema_build_aggregate_rating( $product );
	if ( null !== $rating ) {
		$schema['aggregateRating'] = $rating;
	}

	/**
	 * Filter the Product schema array before emission.
	 *
	 * @since 8.8.1
	 * @param array<string, mixed> $schema  The assembled schema array.
	 * @param WC_Product           $product The WooCommerce product object.
	 */
	return (array) apply_filters( 'lafka_schema_product', $schema, $product );
}

/**
 * Build an Offer or AggregateOffer for a product.
 *
 * @param WC_Product $product
 * @param string     $url      Permalink of the product page.
 * @return array<string, mixed>|null
 */
function lafka_schema_build_product_offer( WC_Product $product, string $url ): ?array {
	$avail          = $product->is_in_stock()
		? 'https://schema.org/InStock'
		: 'https://schema.org/OutOfStock';
	$valid_until    = gmdate( 'Y-m-d', strtotime( '+1 year' ) );

	if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
		/** @var WC_Product_Variable $product */
		$prices = $product->get_variation_prices( true );
		if ( ! empty( $prices['price'] ) ) {
			$low  = min( $prices['price'] );
			$high = max( $prices['price'] );
			if ( $low !== $high ) {
				return array(
					'@type'           => 'AggregateOffer',
					'url'             => $url,
					'lowPrice'        => number_format( (float) $low, 2, '.', '' ),
					'highPrice'       => number_format( (float) $high, 2, '.', '' ),
					'priceCurrency'   => lafka_schema_get_price_currency(),
					'availability'    => $avail,
					'priceValidUntil' => $valid_until,
				);
			}
			return array(
				'@type'           => 'Offer',
				'url'             => $url,
				'price'           => number_format( (float) $low, 2, '.', '' ),
				'priceCurrency'   => lafka_schema_get_price_currency(),
				'availability'    => $avail,
				'priceValidUntil' => $valid_until,
			);
		}
	}

	$price = $product->get_price();
	if ( '' === $price ) {
		return null;
	}

	return array(
		'@type'           => 'Offer',
		'url'             => $url,
		'price'           => number_format( (float) $price, 2, '.', '' ),
		'priceCurrency'   => lafka_schema_get_price_currency(),
		'availability'    => $avail,
		'priceValidUntil' => $valid_until,
	);
}

/**
 * Build an AggregateRating block if the product has reviews.
 *
 * @param WC_Product $product
 * @return array<string, mixed>|null  Null when no reviews exist.
 */
function lafka_schema_build_aggregate_rating( WC_Product $product ): ?array {
	$count  = (int) $product->get_review_count();
	$rating = (float) $product->get_average_rating();

	if ( 0 === $count || 0.0 === $rating ) {
		return null;
	}

	return array(
		'@type'       => 'AggregateRating',
		'ratingValue' => number_format( $rating, 1, '.', '' ),
		'reviewCount' => $count,
		'bestRating'  => '5',
		'worstRating' => '1',
	);
}
