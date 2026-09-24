<?php
/**
 * Social share links for single posts and products: lafka_share_links()
 * renders them, lafka_has_to_show_share() decides whether they show.
 *
 * Moved verbatim out of lafka-plugin.php; function names are unchanged
 * (themes call them).
 *
 * @package Lafka\Plugin
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_share_links' ) ) {

	/**
	 * Displays social networks share links
	 *
	 * @param $title
	 * @param $link
	 */
	function lafka_share_links( $title, $link ) {

		$has_to_show_share = lafka_has_to_show_share();

		if ( $has_to_show_share ) {
			global $post;

			$media         = get_the_post_thumbnail_url( $post->ID, 'large' );
			$decoded_title = html_entity_decode( $title );

			// v9.7.24: filterable network list. Pre-fix the 5 hardcoded
			// networks (Facebook / Twitter / Pinterest / LinkedIn / VK)
			// were frozen circa 2015 — operators couldn't add WhatsApp,
			// Telegram, Mastodon, BlueSky, or even an email-this-page link
			// without forking. Now defaults include the modern essentials
			// and child plugins / themes hook the filter to extend.
			//
			// rawurlencode (not urlencode) for URL query params per RFC 3986;
			// esc_url() on the full href as defense-in-depth even though
			// hosts are hardcoded; HTTPS on every endpoint.
			//
			// Filter signature:
			//   apply_filters( 'lafka_share_networks',
			//     array $defaults, string $title, string $link, string $media )
			//   → array<string, array{ label:string, url:string }>
			$networks = (array) apply_filters(
				'lafka_share_networks',
				array(
					'facebook'  => array(
						'label' => esc_attr__( 'Share on Facebook', 'lafka-plugin' ),
						'url'   => 'https://www.facebook.com/sharer.php?u=' . rawurlencode( $link ) . '&t=' . rawurlencode( $decoded_title ),
					),
					'twitter'   => array(
						'label' => esc_attr__( 'Share on X (Twitter)', 'lafka-plugin' ),
						'url'   => 'https://twitter.com/share?text=' . rawurlencode( $decoded_title ) . '&url=' . rawurlencode( $link ),
					),
					'pinterest' => array(
						'label' => esc_attr__( 'Share on Pinterest', 'lafka-plugin' ),
						'url'   => 'https://pinterest.com/pin/create/button?media=' . rawurlencode( (string) $media ) . '&url=' . rawurlencode( $link ) . '&description=' . rawurlencode( $decoded_title ),
					),
					'linkedin'  => array(
						'label' => esc_attr__( 'Share on LinkedIn', 'lafka-plugin' ),
						'url'   => 'https://www.linkedin.com/shareArticle?url=' . rawurlencode( $link ) . '&title=' . rawurlencode( $decoded_title ),
					),
					'whatsapp'  => array(
						'label' => esc_attr__( 'Share on WhatsApp', 'lafka-plugin' ),
						// `wa.me` redirects to native app on mobile, web.whatsapp.com on desktop.
						'url'   => 'https://wa.me/?text=' . rawurlencode( $decoded_title . ' ' . $link ),
					),
					'telegram'  => array(
						'label' => esc_attr__( 'Share on Telegram', 'lafka-plugin' ),
						'url'   => 'https://t.me/share/url?url=' . rawurlencode( $link ) . '&text=' . rawurlencode( $decoded_title ),
					),
					'email'     => array(
						'label' => esc_attr__( 'Share by email', 'lafka-plugin' ),
						'url'   => 'mailto:?subject=' . rawurlencode( $decoded_title ) . '&body=' . rawurlencode( $link ),
					),
					'vkontakte' => array(
						// Legacy network kept for back-compat with existing CSS overrides.
						'label' => esc_attr__( 'Share on VK', 'lafka-plugin' ),
						'url'   => 'https://vk.com/share.php?url=' . rawurlencode( $link ) . '&title=' . rawurlencode( $decoded_title ) . '&image=' . rawurlencode( (string) $media ),
					),
				),
				$decoded_title,
				$link,
				(string) $media
			);

			$share_links_html = '<span>' . esc_html__( 'Share', 'lafka-plugin' ) . ':</span>';
			foreach ( $networks as $key => $net ) {
				if ( ! is_array( $net ) || empty( $net['url'] ) || empty( $net['label'] ) ) {
					continue;
				}
				$share_links_html .= sprintf(
					'<a class="lafka-share-%s" title="%s" href="%s" target="_blank" rel="noopener noreferrer"><span class="screen-reader-text">%s</span></a>',
					esc_attr( $key ),
					esc_attr( $net['label'] ),
					esc_url( $net['url'] ),
					esc_html( $net['label'] )
				);
			}

			// Each <a> built above is fully escaped; wp_kses_post on the
			// container is defense-in-depth for any future addition that
			// might forget per-piece escaping.
			echo '<div class="lafka-share-links">' . wp_kses_post( $share_links_html ) . '<div class="clear"></div></div>';
		}
	}
}

if ( ! function_exists( 'lafka_has_to_show_share' ) ) {
	function lafka_has_to_show_share() {

		if ( function_exists( 'lafka_get_option' ) ) {
			$general_option         = get_option( 'lafka_share_on_posts' ) === 'yes';
			$general_option_product = get_option( 'lafka_share_on_products' ) === 'yes';
			$single_meta            = get_post_meta( get_the_ID(), 'lafka_show_share', true );

			$target = 'single';
			if ( function_exists( 'is_product' ) && is_product() ) {
				$target = 'product';
			}

			$has_to_show_share = false;

			if ( $target === 'single' && $single_meta === 'yes' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'single' && $general_option && $single_meta !== 'no' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'product' && $general_option_product ) {
				$has_to_show_share = true;
			}

			return $has_to_show_share;
		}

		return false;
	}
}
