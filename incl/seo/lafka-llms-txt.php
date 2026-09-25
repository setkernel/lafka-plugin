<?php
/**
 * GX3: machine-readable restaurant facts for AI assistants and crawlers.
 *
 *   /llms.txt       — llmstxt.org-style summary: name, one-paragraph summary,
 *                     address / phone / hours, service areas, how to order,
 *                     menu categories with price ranges, links.
 *   /llms-full.txt  — the same header plus the full menu (every item with
 *                     price, diet labels and description) and FAQs.
 *   /menu.md        — the full menu as Markdown.
 *   /menu.json      — schema.org JSON-LD (Restaurant + Menu), identical in
 *                     shape to the structured data on the site.
 *
 * Everything is generated from the SAME sources as the JSON-LD —
 * lafka_get_restaurant_info() and lafka_schema_menu_data() — so the four
 * documents can never contradict the structured data. Output is cached in
 * transients, dropped whenever the menu data changes
 * (`lafka_menu_data_changed`) or a business / Search & AI option is saved.
 *
 * Toggle: WooCommerce → Settings → Restaurant → Search & AI → "Machine-
 * readable menu" (`lafka_seo_llms_enabled`, default ON); `lafka_llms_enabled`
 * filter has the last word; `lafka_llms_document` filters each document.
 *
 * Rewrite rules are flushed once per LAFKA_SEO_REWRITE_VERSION (activation
 * and upgrades), never on a normal request.
 *
 * @package Lafka\Plugin\SEO
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_SEO_REWRITE_VERSION' ) ) {
	/** Bump whenever the machine-readable / IndexNow rewrite rules change. */
	define( 'LAFKA_SEO_REWRITE_VERSION', '1' );
}

if ( ! function_exists( 'lafka_llms_documents' ) ) {
	/**
	 * Public path => document type.
	 *
	 * @return array<string,string>
	 */
	function lafka_llms_documents(): array {
		return array(
			'llms.txt'      => 'llms',
			'llms-full.txt' => 'llms-full',
			'menu.md'       => 'menu-md',
			'menu.json'     => 'menu-json',
		);
	}
}

if ( ! function_exists( 'lafka_llms_enabled' ) ) {
	/**
	 * Whether the machine-readable documents are published.
	 *
	 * @return bool
	 */
	function lafka_llms_enabled(): bool {
		$on = function_exists( 'lafka_seo_is_on' ) ? lafka_seo_is_on( 'lafka_seo_llms_enabled' ) : true;
		/**
		 * Filter whether /llms.txt, /llms-full.txt, /menu.md, /menu.json are served.
		 *
		 * @since 10.2.0
		 * @param bool $on Option value.
		 */
		return (bool) apply_filters( 'lafka_llms_enabled', $on );
	}
}

if ( ! function_exists( 'lafka_llms_register_rewrites' ) ) {
	/**
	 * Register the four document routes (always — the handler 404s when the
	 * toggle is off, so flipping it never needs a rewrite flush).
	 *
	 * @return void
	 */
	function lafka_llms_register_rewrites() {
		foreach ( lafka_llms_documents() as $path => $type ) {
			add_rewrite_rule( '^' . preg_quote( $path, '/' ) . '$', 'index.php?lafka_machine=' . $type, 'top' );
		}
	}
}

if ( ! function_exists( 'lafka_llms_query_vars' ) ) {
	/**
	 * @param array<int,string> $vars Public query vars.
	 * @return array<int,string>
	 */
	function lafka_llms_query_vars( $vars ) {
		$vars[] = 'lafka_machine';
		return $vars;
	}
}

if ( ! function_exists( 'lafka_seo_maybe_flush_rewrites' ) ) {
	/**
	 * Flush rewrite rules once per LAFKA_SEO_REWRITE_VERSION — covers fresh
	 * activation (the activation hook clears the stamp) and in-place upgrades.
	 * Runs late on `init`, after every module registered its rules.
	 *
	 * @return void
	 */
	function lafka_seo_maybe_flush_rewrites() {
		if ( LAFKA_SEO_REWRITE_VERSION === (string) get_option( 'lafka_seo_rewrite_version', '' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'lafka_seo_rewrite_version', LAFKA_SEO_REWRITE_VERSION );
	}
}

if ( ! function_exists( 'lafka_seo_reset_rewrite_stamp' ) ) {
	/**
	 * Activation hook: forget the stamp so the next `init` flushes.
	 *
	 * @return void
	 */
	function lafka_seo_reset_rewrite_stamp() {
		delete_option( 'lafka_seo_rewrite_version' );
	}
}

if ( ! function_exists( 'lafka_llms_diet_label' ) ) {
	/**
	 * "https://schema.org/GlutenFreeDiet" → "gluten-free".
	 *
	 * @param string $diet Diet URL.
	 * @return string
	 */
	function lafka_llms_diet_label( string $diet ): string {
		$name = (string) preg_replace( '#^https?://schema\.org/#', '', $diet );
		$name = (string) preg_replace( '/Diet$/', '', $name );
		return strtolower( (string) preg_replace( '/(?<=[a-z])(?=[A-Z])/', '-', $name ) );
	}
}

if ( ! function_exists( 'lafka_llms_offer_text' ) ) {
	/**
	 * Plain-text price of a MenuItem ("$12.00" / "$8.00–$14.50" / '').
	 *
	 * @param array<string,mixed> $item MenuItem node.
	 * @return string
	 */
	function lafka_llms_offer_text( array $item ): string {
		$offer = isset( $item['offers'] ) && is_array( $item['offers'] ) ? $item['offers'] : array();
		if ( isset( $offer['lowPrice'], $offer['highPrice'] ) ) {
			return lafka_seo_format_price( $offer['lowPrice'] ) . '–' . lafka_seo_format_price( $offer['highPrice'] );
		}
		return isset( $offer['price'] ) ? lafka_seo_format_price( $offer['price'] ) : '';
	}
}

if ( ! function_exists( 'lafka_llms_md' ) ) {
	/**
	 * Flatten operator text to one Markdown-safe line.
	 *
	 * @param string $text Text (HTML allowed).
	 * @return string
	 */
	function lafka_llms_md( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}

if ( ! function_exists( 'lafka_llms_summary' ) ) {
	/**
	 * One-paragraph summary: the operator's description, else a factual
	 * sentence built from name / cuisines / locality.
	 *
	 * @param array<string,mixed> $info Restaurant info.
	 * @return string
	 */
	function lafka_llms_summary( array $info ): string {
		if ( ! empty( $info['description'] ) ) {
			return lafka_llms_md( (string) $info['description'] );
		}
		$name     = (string) ( $info['name'] ?? '' );
		$cuisines = implode( ', ', (array) ( $info['cuisines'] ?? array() ) );
		$place    = trim( implode( ', ', array_filter( array( (string) ( $info['city'] ?? '' ), (string) ( $info['region'] ?? '' ) ) ) ) );
		if ( '' !== $cuisines && '' !== $place ) {
			/* translators: 1: restaurant name, 2: cuisine list, 3: city, region. */
			return sprintf( __( '%1$s serves %2$s in %3$s, with online ordering on this website.', 'lafka-plugin' ), $name, $cuisines, $place );
		}
		if ( '' !== $place ) {
			/* translators: 1: restaurant name, 2: city, region. */
			return sprintf( __( '%1$s is a restaurant in %2$s, with online ordering on this website.', 'lafka-plugin' ), $name, $place );
		}
		$tagline = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '';
		return '' !== $tagline ? lafka_llms_md( $tagline ) : $name;
	}
}

if ( ! function_exists( 'lafka_llms_header_lines' ) ) {
	/**
	 * Shared head of llms.txt / llms-full.txt: title, summary, contact,
	 * hours, service areas, how to order.
	 *
	 * @param array<string,mixed> $info Restaurant info.
	 * @return list<string>
	 */
	function lafka_llms_header_lines( array $info ): array {
		$lines   = array();
		$lines[] = '# ' . lafka_llms_md( (string) ( $info['name'] ?? '' ) );
		$lines[] = '';
		$lines[] = '> ' . lafka_llms_summary( $info );
		$lines[] = '';

		$contact = array();
		$address = str_replace( "\n", ', ', (string) ( $info['address_display'] ?? '' ) );
		if ( '' !== $address ) {
			$contact[] = '- ' . __( 'Address', 'lafka-plugin' ) . ': ' . lafka_llms_md( $address );
		}
		if ( ! empty( $info['phone_display'] ) ) {
			$contact[] = '- ' . __( 'Phone', 'lafka-plugin' ) . ': ' . lafka_llms_md( (string) $info['phone_display'] ) . ( ! empty( $info['phone_e164'] ) ? ' (' . $info['phone_e164'] . ')' : '' );
		}
		if ( ! empty( $info['email'] ) ) {
			$contact[] = '- ' . __( 'Email', 'lafka-plugin' ) . ': ' . lafka_llms_md( (string) $info['email'] );
		}
		if ( ! empty( $info['map_url'] ) ) {
			$contact[] = '- ' . __( 'Map', 'lafka-plugin' ) . ': ' . $info['map_url'];
		}
		if ( ! empty( $info['price_range'] ) ) {
			$contact[] = '- ' . __( 'Price range', 'lafka-plugin' ) . ': ' . $info['price_range'];
		}
		if ( ! empty( $contact ) ) {
			$lines[] = '## ' . __( 'Location & contact', 'lafka-plugin' );
			$lines[] = '';
			$lines   = array_merge( $lines, $contact );
			$lines[] = '';
		}

		if ( ! empty( $info['hours'] ) && is_array( $info['hours'] ) ) {
			$lines[] = '## ' . __( 'Opening hours', 'lafka-plugin' );
			$lines[] = '';
			foreach ( $info['hours'] as $day => $range ) {
				$lines[] = '- ' . $day . ': ' . ( 'Closed' === $range ? __( 'Closed', 'lafka-plugin' ) : str_replace( '-', '–', (string) $range ) );
			}
			$tz = function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '';
			if ( '' !== $tz ) {
				/* translators: %s: timezone identifier. */
				$lines[] = '- ' . sprintf( __( 'Times are local (%s).', 'lafka-plugin' ), $tz );
			}
			$lines[] = '';
		}

		$areas = (array) ( $info['service_areas'] ?? array() );
		if ( ! empty( $areas ) ) {
			$lines[] = '## ' . __( 'Service areas', 'lafka-plugin' );
			$lines[] = '';
			foreach ( $areas as $area ) {
				$lines[] = '- ' . lafka_llms_md( (string) $area );
			}
			$lines[] = '';
		}

		$lines[] = '## ' . __( 'How to order', 'lafka-plugin' );
		$lines[] = '';
		if ( ! empty( $info['menu_url'] ) ) {
			$lines[] = '- ' . __( 'Online', 'lafka-plugin' ) . ': ' . $info['menu_url'];
		}
		if ( ! empty( $info['phone_display'] ) ) {
			$lines[] = '- ' . __( 'By phone', 'lafka-plugin' ) . ': ' . lafka_llms_md( (string) $info['phone_display'] );
		}
		$lines[] = '';

		return $lines;
	}
}

if ( ! function_exists( 'lafka_llms_menu_lines' ) ) {
	/**
	 * Full menu as Markdown: one "##" per section, one bullet per item.
	 *
	 * @param array{sections:array<int,array<string,mixed>>,order:list<int>} $data     Menu data.
	 * @param bool                                                           $with_faq Append each category's FAQ.
	 * @return list<string>
	 */
	function lafka_llms_menu_lines( array $data, bool $with_faq ): array {
		$lines = array();
		foreach ( $data['order'] as $id ) {
			$section = $data['sections'][ $id ] ?? null;
			if ( ! is_array( $section ) || empty( $section['items'] ) ) {
				continue;
			}
			$lines[] = '## ' . lafka_llms_md( (string) $section['name'] );
			$lines[] = '';
			if ( '' !== trim( (string) $section['description'] ) ) {
				$lines[] = lafka_llms_md( (string) $section['description'] );
				$lines[] = '';
			}
			foreach ( $section['items'] as $item ) {
				$bits  = array( '**' . lafka_llms_md( (string) $item['name'] ) . '**' );
				$price = lafka_llms_offer_text( $item );
				if ( '' !== $price ) {
					$bits[] = $price;
				}
				$diets = isset( $item['suitableForDiet'] ) ? (array) $item['suitableForDiet'] : array();
				if ( ! empty( $diets ) ) {
					$bits[] = '(' . implode( ', ', array_map( 'lafka_llms_diet_label', $diets ) ) . ')';
				}
				if ( isset( $item['offers']['availability'] ) && false !== strpos( (string) $item['offers']['availability'], 'OutOfStock' ) ) {
					$bits[] = '[' . __( 'currently unavailable', 'lafka-plugin' ) . ']';
				}
				$line = '- ' . implode( ' — ', $bits );
				if ( ! empty( $item['description'] ) ) {
					$line .= ': ' . lafka_llms_md( (string) $item['description'] );
				}
				if ( ! empty( $item['url'] ) ) {
					$line .= ' <' . $item['url'] . '>';
				}
				$lines[] = $line;
			}
			$lines[] = '';

			if ( $with_faq && function_exists( 'lafka_seo_get_term_faqs' ) ) {
				$faqs = lafka_seo_get_term_faqs( (int) $id );
				if ( ! empty( $faqs ) ) {
					/* translators: %s: menu category name. */
					$lines[] = '### ' . sprintf( __( '%s — questions', 'lafka-plugin' ), lafka_llms_md( (string) $section['name'] ) );
					$lines[] = '';
					foreach ( $faqs as $faq ) {
						$lines[] = '- **' . lafka_llms_md( $faq['q'] ) . '** ' . lafka_llms_md( $faq['a'] );
					}
					$lines[] = '';
				}
			}
		}
		return $lines;
	}
}

if ( ! function_exists( 'lafka_llms_contact_faqs' ) ) {
	/**
	 * Operator-written contact-page FAQs (never the theme's placeholder copy).
	 *
	 * @return list<array{q:string,a:string}>
	 */
	function lafka_llms_contact_faqs(): array {
		if ( ! function_exists( 'lafka_schema_faq_items_from_theme_mods' ) ) {
			return array();
		}
		$items = apply_filters( 'lafka_contact_faqs', lafka_schema_faq_items_from_theme_mods() );
		return function_exists( 'lafka_seo_normalize_faqs' ) ? lafka_seo_normalize_faqs( $items ) : array();
	}
}

if ( ! function_exists( 'lafka_llms_render' ) ) {
	/**
	 * Build one document (uncached).
	 *
	 * @param string $type llms | llms-full | menu-md | menu-json.
	 * @return string
	 */
	function lafka_llms_render( string $type ): string {
		$info = lafka_get_restaurant_info();
		$data = function_exists( 'lafka_schema_menu_data' ) ? lafka_schema_menu_data() : array(
			'sections' => array(),
			'order'    => array(),
		);
		$home = trailingslashit( home_url( '/' ) );

		if ( 'menu-json' === $type ) {
			$graph = array();
			if ( lafka_schema_has_restaurant_basics() ) {
				$graph[] = lafka_schema_restaurant();
			}
			$menu = ! empty( $data['sections'] ) ? lafka_schema_menu_node( $data, $data['order'], 'full' ) : null;
			if ( null !== $menu ) {
				$graph[] = $menu;
			}
			$doc = (string) wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => array_values( array_filter( $graph ) ),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
			);
			return (string) apply_filters( 'lafka_llms_document', $doc, $type, $info, $data );
		}

		$lines = array();
		if ( 'menu-md' === $type ) {
			/* translators: %s: restaurant name. */
			$lines[] = '# ' . sprintf( __( '%s — Menu', 'lafka-plugin' ), lafka_llms_md( (string) ( $info['name'] ?? '' ) ) );
			$lines[] = '';
			$currency = lafka_schema_get_price_currency();
			/* translators: 1: ISO currency code, 2: date. */
			$lines[] = '> ' . sprintf( __( 'Prices in %1$s. Generated %2$s from the live online menu:', 'lafka-plugin' ), $currency, gmdate( 'Y-m-d' ) ) . ' ' . (string) ( $info['menu_url'] ?? '' );
			$lines[] = '';
			$lines   = array_merge( $lines, lafka_llms_menu_lines( $data, false ) );
		} else {
			$lines = lafka_llms_header_lines( $info );

			if ( 'llms' === $type ) {
				if ( ! empty( $data['order'] ) ) {
					$lines[] = '## ' . __( 'Menu', 'lafka-plugin' );
					$lines[] = '';
					foreach ( $data['order'] as $id ) {
						$section = $data['sections'][ $id ] ?? null;
						if ( ! is_array( $section ) || empty( $section['items'] ) ) {
							continue;
						}
						$count = count( $section['items'] );
						/* translators: %d: number of menu items. */
						$bit = sprintf( _n( '%d item', '%d items', $count, 'lafka-plugin' ), $count );
						if ( '' !== (string) $section['price_min'] ) {
							$range = lafka_seo_format_price( $section['price_min'] );
							if ( (string) $section['price_max'] !== (string) $section['price_min'] ) {
								$range .= '–' . lafka_seo_format_price( $section['price_max'] );
							}
							$bit .= ', ' . $range;
						}
						$link    = '' !== (string) $section['url'] ? '[' . lafka_llms_md( (string) $section['name'] ) . '](' . $section['url'] . ')' : lafka_llms_md( (string) $section['name'] );
						$lines[] = '- ' . $link . ': ' . $bit;
					}
					$lines[] = '';
				}
			} else {
				$lines = array_merge( $lines, lafka_llms_menu_lines( $data, true ) );
				$faqs  = lafka_llms_contact_faqs();
				if ( ! empty( $faqs ) ) {
					$lines[] = '## ' . __( 'Frequently asked questions', 'lafka-plugin' );
					$lines[] = '';
					foreach ( $faqs as $faq ) {
						$lines[] = '- **' . lafka_llms_md( $faq['q'] ) . '** ' . lafka_llms_md( $faq['a'] );
					}
					$lines[] = '';
				}
			}

			$lines[] = '## ' . __( 'Links', 'lafka-plugin' );
			$lines[] = '';
			if ( ! empty( $info['menu_url'] ) ) {
				$lines[] = '- [' . __( 'Order online', 'lafka-plugin' ) . '](' . $info['menu_url'] . ')';
			}
			$lines[] = '- [' . __( 'Full menu (Markdown)', 'lafka-plugin' ) . '](' . $home . 'menu.md)';
			$lines[] = '- [' . __( 'Full menu (schema.org JSON)', 'lafka-plugin' ) . '](' . $home . 'menu.json)';
			if ( 'llms' === $type ) {
				$lines[] = '- [' . __( 'Everything in one file', 'lafka-plugin' ) . '](' . $home . 'llms-full.txt)';
			}
			foreach ( (array) ( $info['same_as'] ?? array() ) as $url ) {
				$lines[] = '- ' . $url;
			}
			$lines[] = '';
		}

		$doc = implode( "\n", $lines );

		/**
		 * Filter a machine-readable document before it is cached and served.
		 *
		 * @since 10.2.0
		 * @param string              $doc  Document body.
		 * @param string              $type llms | llms-full | menu-md | menu-json.
		 * @param array<string,mixed> $info Restaurant info.
		 * @param array<string,mixed> $data Menu data.
		 */
		return (string) apply_filters( 'lafka_llms_document', $doc, $type, $info, $data );
	}
}

if ( ! function_exists( 'lafka_llms_document' ) ) {
	/**
	 * A document, served from cache when possible (12h, busted on change).
	 *
	 * @param string $type Document type.
	 * @return string
	 */
	function lafka_llms_document( string $type ): string {
		$key    = 'lafka_llms_' . str_replace( '-', '_', $type );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$doc = lafka_llms_render( $type );
		set_transient( $key, $doc, 12 * HOUR_IN_SECONDS );
		return $doc;
	}
}

if ( ! function_exists( 'lafka_llms_flush_cache' ) ) {
	/**
	 * Drop every cached document.
	 *
	 * @return void
	 */
	function lafka_llms_flush_cache() {
		foreach ( lafka_llms_documents() as $type ) {
			delete_transient( 'lafka_llms_' . str_replace( '-', '_', $type ) );
		}
	}
}

if ( ! function_exists( 'lafka_llms_maybe_flush_on_option' ) ) {
	/**
	 * Bust the cache when a business / Search & AI / site-identity option changes.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	function lafka_llms_maybe_flush_on_option( $option ) {
		$option = (string) $option;
		if ( 0 === strpos( $option, 'lafka_business_' ) || 0 === strpos( $option, 'lafka_seo_' ) || 0 === strpos( $option, 'woocommerce_store_' )
			|| in_array( $option, array( 'blogname', 'blogdescription', 'woocommerce_currency', 'woocommerce_default_country', 'lafka_order_hours_options' ), true ) ) {
			lafka_llms_flush_cache();
		}
	}
}

if ( ! function_exists( 'lafka_llms_serve' ) ) {
	/**
	 * `template_redirect`: serve a machine-readable document, or 404 when
	 * the feature is off.
	 *
	 * @return void
	 */
	function lafka_llms_serve() {
		$type = (string) get_query_var( 'lafka_machine' );
		if ( '' === $type || ! in_array( $type, lafka_llms_documents(), true ) ) {
			return;
		}
		if ( ! lafka_llms_enabled() ) {
			global $wp_query;
			if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			return;
		}

		$types = array(
			'llms'      => 'text/plain; charset=utf-8',
			'llms-full' => 'text/plain; charset=utf-8',
			'menu-md'   => 'text/markdown; charset=utf-8',
			'menu-json' => 'application/json; charset=utf-8',
		);
		status_header( 200 );
		header( 'Content-Type: ' . $types[ $type ] );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 'menu-json' === $type ) {
			header( 'X-Robots-Tag: noindex' );
		}
		echo lafka_llms_document( $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text / JSON document, served with a non-HTML Content-Type and nosniff.
		exit;
	}
}

if ( ! function_exists( 'lafka_llms_register_module' ) ) {
	/**
	 * List the machine-readable menu on Lafka → Modules (same option as the
	 * settings toggle).
	 *
	 * @return void
	 */
	function lafka_llms_register_module() {
		if ( ! class_exists( 'Lafka_Module' ) || ! class_exists( 'Lafka_Module_Registry' ) ) {
			return;
		}
		Lafka_Module_Registry::register(
			new Lafka_Module(
				array(
					'id'              => 'machine_readable_menu',
					'label'           => esc_html__( 'AI-readable menu (llms.txt)', 'lafka-plugin' ),
					'description'     => esc_html__( 'Publish /llms.txt, /llms-full.txt, /menu.md and /menu.json: your hours, address and full menu with prices, in plain text for AI assistants.', 'lafka-plugin' ),
					'category'        => 'seo',
					'storage'         => 'option',
					'default_enabled' => true,
					'get_enabled'     => static function () {
						return lafka_seo_is_on( 'lafka_seo_llms_enabled' );
					},
					'set_enabled'     => static function ( bool $enabled ) {
						update_option( 'lafka_seo_llms_enabled', $enabled ? 'yes' : 'no' );
					},
					'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=search',
					'docs_slug'       => 'llms-txt',
				)
			)
		);
	}
}

add_action( 'lafka_register_modules', 'lafka_llms_register_module' );
add_action( 'init', 'lafka_llms_register_rewrites' );
add_action( 'init', 'lafka_seo_maybe_flush_rewrites', 99 );
add_filter( 'query_vars', 'lafka_llms_query_vars' );
add_action( 'template_redirect', 'lafka_llms_serve', 0 );
add_action( 'lafka_menu_data_changed', 'lafka_llms_flush_cache' );
add_action( 'updated_option', 'lafka_llms_maybe_flush_on_option' );
add_action( 'added_option', 'lafka_llms_maybe_flush_on_option' );
if ( defined( 'LAFKA_PLUGIN_FILE' ) && function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( LAFKA_PLUGIN_FILE, 'lafka_seo_reset_rewrite_stamp' );
}
