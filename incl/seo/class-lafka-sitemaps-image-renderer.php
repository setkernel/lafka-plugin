<?php
/**
 * GX3: WordPress core sitemap renderer + Google image-sitemap extension.
 *
 * Core's WP_Sitemaps_Renderer writes only loc / lastmod / changefreq /
 * priority (anything else triggers _doing_it_wrong). This subclass declares
 * the image namespace and turns an entry's `lafka_images` list (added by
 * lafka_sitemap_product_images()) into <image:image><image:loc> children.
 * Everything else is rendered exactly as core does. Loaded only from the
 * `wp_sitemaps_init` hook, when the parent class is guaranteed to exist.
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'WP_Sitemaps_Renderer' ) && ! class_exists( 'Lafka_Sitemaps_Image_Renderer' ) ) {

	/**
	 * Sitemap renderer that also emits image entries.
	 */
	class Lafka_Sitemaps_Image_Renderer extends WP_Sitemaps_Renderer {

		/** Google image sitemap namespace. */
		const IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';

		/**
		 * Render a sitemap URL list as XML.
		 *
		 * @param array<int,array<string,mixed>> $url_list Entries.
		 * @return string|false
		 */
		public function get_sitemap_xml( $url_list ) {
			$urlset = new SimpleXMLElement(
				sprintf(
					'%1$s%2$s%3$s',
					'<?xml version="1.0" encoding="UTF-8" ?>',
					$this->stylesheet,
					'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="' . self::IMAGE_NS . '" />'
				)
			);

			foreach ( $url_list as $url_item ) {
				$url = $urlset->addChild( 'url' );
				foreach ( $url_item as $name => $value ) {
					if ( 'loc' === $name ) {
						$url->addChild( $name, esc_url( $value ) );
					} elseif ( in_array( $name, array( 'lastmod', 'changefreq', 'priority' ), true ) ) {
						$url->addChild( $name, esc_xml( $value ) );
					} elseif ( 'lafka_images' === $name ) {
						foreach ( (array) $value as $src ) {
							$image = $url->addChild( 'image:image', null, self::IMAGE_NS );
							$image->addChild( 'image:loc', esc_url( (string) $src ), self::IMAGE_NS );
						}
					}
				}
			}

			return $urlset->asXML();
		}
	}
}
