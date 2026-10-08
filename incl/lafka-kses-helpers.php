<?php
/**
 * Allowlists for wp_kses() shared across the plugin.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags and attributes of an image element as produced by WordPress and
 * WooCommerce (get_the_post_thumbnail(), wp_get_attachment_image(),
 * WC_Product::get_image()), for use as the allowlist of wp_kses().
 *
 * @return array<string,array<string,bool>>
 */
function lafka_kses_allowed_image_html(): array {
	return array(
		'img' => array(
			'src'           => true,
			'srcset'        => true,
			'sizes'         => true,
			'alt'           => true,
			'title'         => true,
			'class'         => true,
			'id'            => true,
			'width'         => true,
			'height'        => true,
			'loading'       => true,
			'decoding'      => true,
			'fetchpriority' => true,
			'data-*'        => true,
		),
	);
}
