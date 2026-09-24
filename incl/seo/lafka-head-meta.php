<?php
/**
 * Head metadata: OpenGraph + Twitter Card tags, <meta name="description">
 * and the <html lang> override. The tag and description emitters defer to
 * an active SEO plugin (lafka_seo_plugin_active(), in
 * incl/seo/lafka-seo-plugin-detect.php) unless the
 * `lafka_head_meta_force_emit` filter returns true.
 *
 * Moved verbatim out of lafka-plugin.php; function names, hooks and
 * priorities are unchanged (public API).
 *
 * @package Lafka\Plugin\SEO
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'lafka_insert_og_tags' );
if ( ! function_exists( 'lafka_insert_og_tags' ) ) {
	/**
	 * Emit OpenGraph + Twitter Card tags on every public page.
	 * P6-SEO-5: full coverage (was og:image only).
	 *
	 * v9.22.2 image fallback chain (first non-empty wins):
	 *   1. Per-post `_lafka_og_image` post meta (manual override on any page).
	 *   2. Featured image of the singular post/product.
	 *   3. Customizer `lafka_og_image_default` (operator-pinned hero photo).
	 *   4. Site icon (last-resort fallback).
	 *
	 * Without the Customizer default, archive pages like /menu/ and
	 * /contact-us/ emitted no `og:image` at all — bad social-share previews.
	 *
	 * v9.22.2 locale: emit goes through `lafka_og_locale` filter; operator
	 * can pin a non-WP-Settings locale (e.g. en_CA when Site Language is
	 * still en_US) via Customizer `lafka_default_locale`. Same value drives
	 * `<html lang>` via the language_attributes filter below.
	 */
	function lafka_insert_og_tags() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		/*
		 * Defer to a dedicated SEO plugin (Yoast / Rank Math / SEOPress /
		 * AIOSEO) when one is active — it already emits a full set of
		 * og:* / twitter:* tags. Emitting ours alongside theirs duplicates
		 * the OpenGraph/Twitter Card metadata on every public page and
		 * confuses social scrapers. Mirrors the JSON-LD @graph deferral in
		 * incl/schema/class-lafka-json-ld.php so a single "an SEO plugin
		 * owns head metadata" decision (lafka_seo_plugin_active()) governs
		 * all head emitters.
		 *
		 * Operators who want Lafka's tags regardless can override via the
		 * `lafka_head_meta_force_emit` filter (return true) — the head-meta
		 * sibling of `lafka_schema_force_emit`.
		 */
		if ( lafka_seo_plugin_active() && ! (bool) apply_filters( 'lafka_head_meta_force_emit', false ) ) {
			return;
		}

		global $post;

		// ===== Resolve title / description / URL / image / type per context =====
		// Front-page check MUST come before is_singular() — when the homepage is a
		// static page (Settings → Reading), both are true. We want the front-page
		// branch to win so the homepage carries og:type=restaurant.restaurant and
		// og:title=site-name (not the page's literal title like "Home New").
		// We still pass $post to the description resolver so any per-page
		// _lafka_meta_description override is honored on the static front page.
		// Resolve image URL + actual width/height. Pre-v9.7.24 the dimensions
		// were always WP's `large_size_w`/`large_size_h` option (default
		// 1024×1024) regardless of the actual image — so a portrait 800×1200
		// thumbnail emitted og:image:width=1024, og:image:height=1024,
		// causing Facebook/LinkedIn/Slack to crop badly or compute wrong
		// aspect ratios in cached previews.
		//
		// Now we look up the actual image src array via
		// wp_get_attachment_image_src(), which returns [url, width, height,
		// is_intermediate]. For site-icon fallback we know the requested
		// size (1200×1200 — site icons are square).
		$image        = '';
		$image_width  = 0;
		$image_height = 0;

		$resolve_post_image = static function ( $post_id ) use ( &$image, &$image_width, &$image_height ) {
			// Tier 1: per-post override via `_lafka_og_image` post meta.
			// Stored as either a numeric attachment ID or a raw URL.
			$override = get_post_meta( (int) $post_id, '_lafka_og_image', true );
			if ( $override ) {
				if ( is_numeric( $override ) ) {
					$src = wp_get_attachment_image_src( (int) $override, 'large' );
					if ( is_array( $src ) && ! empty( $src[0] ) ) {
						$image        = (string) $src[0];
						$image_width  = (int) ( $src[1] ?? 0 );
						$image_height = (int) ( $src[2] ?? 0 );
						return;
					}
				} else {
					$image = (string) $override;
					// Unknown dimensions — emitter will skip width/height tags.
					return;
				}
			}
			// Tier 2: featured image of the singular post/product.
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( ! $thumb_id ) {
				return;
			}
			$src = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( ! is_array( $src ) || empty( $src[0] ) ) {
				return;
			}
			$image        = (string) $src[0];
			$image_width  = (int) ( $src[1] ?? 0 );
			$image_height = (int) ( $src[2] ?? 0 );
		};

		if ( is_front_page() || is_home() ) {
			$title       = get_bloginfo( 'name' );
			$description = lafka_resolve_meta_description( ( is_singular() && $post ) ? $post : null );
			$url         = home_url( '/' );
			if ( is_singular() && $post ) {
				$resolve_post_image( $post->ID );
			}
			$og_type     = 'restaurant.restaurant';
		} elseif ( is_singular() && $post ) {
			$title       = get_the_title( $post );
			$description = lafka_resolve_meta_description( $post );
			$url         = get_permalink( $post );
			$resolve_post_image( $post->ID );
			$og_type     = ( function_exists( 'is_product' ) && is_product() ) ? 'product' : 'article';
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term        = get_queried_object();
			$title       = $term ? $term->name : get_bloginfo( 'name' );
			$description = $term && ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : lafka_resolve_meta_description( null );
			$url         = $term ? get_term_link( $term ) : home_url( '/' );
			$og_type     = 'website';
		} else {
			$title       = wp_get_document_title();
			$description = lafka_resolve_meta_description( null );
			$url         = home_url( add_query_arg( null, null ) );
			$og_type     = 'website';
		}

		// Tier 3: Customizer-pinned default OG image. Applies on any page
		// that fell through tiers 1+2 (archives, /menu/, /contact-us/,
		// homepage without featured image, etc).
		if ( '' === $image ) {
			$og_default = get_theme_mod( 'lafka_og_image_default', '' );
			if ( '' !== $og_default && null !== $og_default ) {
				if ( is_numeric( $og_default ) ) {
					$src = wp_get_attachment_image_src( (int) $og_default, 'large' );
					if ( is_array( $src ) && ! empty( $src[0] ) ) {
						$image        = (string) $src[0];
						$image_width  = (int) ( $src[1] ?? 0 );
						$image_height = (int) ( $src[2] ?? 0 );
					}
				} else {
					$image = (string) $og_default;
					// String URL — dimensions unknown, width/height tags skipped.
				}
			}
		}

		// Tier 4 (last resort): site icon. Square, low resolution — still
		// better than no preview at all.
		if ( '' === $image && function_exists( 'get_site_icon_url' ) ) {
			$icon = get_site_icon_url( 1200 );
			if ( $icon ) {
				$image        = $icon;
				$image_width  = 1200; // site icons are always square at the requested size.
				$image_height = 1200;
			}
		}

		$site_name = get_bloginfo( 'name' );

		// Locale: Customizer default (operator-pinned) takes precedence over
		// WP Settings → General → Site Language. Output normalized to "xx_YY"
		// (underscore, not hyphen). The `lafka_og_locale` filter lets a
		// theme/plugin override per-request without touching settings.
		$customizer_locale = (string) get_theme_mod( 'lafka_default_locale', '' );
		$locale            = '' !== $customizer_locale
			? str_replace( '-', '_', $customizer_locale )
			: str_replace( '-', '_', get_locale() );
		$locale            = (string) apply_filters( 'lafka_og_locale', $locale );

		// ===== Emit =====
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $og_type ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( $site_name ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( $locale ) );

		if ( $image ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
			// Emit dimensions only when we actually know them — emitting wrong
			// dimensions is worse than omitting them (crawlers fall back to
			// fetching+measuring vs trusting bad metadata).
			if ( $image_width > 0 && $image_height > 0 ) {
				printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $image_width );
				printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $image_height );
			}
		}

		printf( '<meta name="twitter:card" content="%s">' . "\n", $image ? 'summary_large_image' : 'summary' );
		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $description ) );
		if ( $image ) {
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $image ) );
		}
	}
}

/**
 * Drive <html lang="…"> from the Customizer `lafka_default_locale` setting
 * (v9.22.2). Without this filter the WP core uses Settings → General →
 * Site Language. Operators on `en_US` WP installs that serve a Canadian
 * audience can pin `en_CA` (or any other locale) via the Customizer
 * "Social Sharing" section without wrangling WP Settings.
 *
 * Frontend only — admin keeps the WP core locale for back-office i18n.
 */
add_filter( 'language_attributes', 'lafka_filter_language_attributes', 10, 2 );
if ( ! function_exists( 'lafka_filter_language_attributes' ) ) {
	/**
	 * @param string $output Existing attribute string e.g. `lang="en-US"`.
	 * @param string $doctype Either 'html' or 'xhtml'.
	 */
	function lafka_filter_language_attributes( $output, $doctype = 'html' ) {
		if ( is_admin() ) {
			return $output;
		}
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return $output;
		}
		$override = (string) get_theme_mod( 'lafka_default_locale', '' );
		if ( '' === $override ) {
			return $output;
		}
		// Allow plugins/themes to short-circuit. Mirror the OG filter name
		// for discoverability — same setting drives both surfaces.
		$override = (string) apply_filters( 'lafka_og_locale', str_replace( '-', '_', $override ) );
		// `<html lang>` uses hyphen form per BCP-47 (e.g. en-CA), so flip
		// the underscore the filter normalised on.
		$lang_attr = str_replace( '_', '-', $override );
		$replacement = sprintf( 'lang="%s"', esc_attr( $lang_attr ) );
		// Replace any existing lang="…" attribute; append when absent.
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			$output = preg_replace( '/\blang="[^"]*"/', $replacement, $output, 1 );
		} else {
			$output = trim( $output . ' ' . $replacement );
		}
		return $output;
	}
}

add_action( 'wp_head', 'lafka_render_meta_description', 1 );
if ( ! function_exists( 'lafka_render_meta_description' ) ) {
	/**
	 * Emit <meta name="description"> from per-post override or context-specific default.
	 * P6-SEO-4 + P6-SEO-5: replaces silent absence of meta description.
	 */
	function lafka_render_meta_description() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		/*
		 * Defer to a dedicated SEO plugin when active — it emits its own
		 * <meta name="description">. See lafka_insert_og_tags() for the full
		 * rationale; the same `lafka_head_meta_force_emit` override applies so
		 * the deferral decision stays consistent across all head emitters.
		 */
		if ( lafka_seo_plugin_active() && ! (bool) apply_filters( 'lafka_head_meta_force_emit', false ) ) {
			return;
		}

		global $post;
		$desc = lafka_resolve_meta_description( is_singular() && $post ? $post : null );
		if ( $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
	}
}

if ( ! function_exists( 'lafka_resolve_meta_description' ) ) {
	/**
	 * Resolution order (first non-empty wins):
	 *   1. Per-post `_lafka_meta_description` post meta (manual override).
	 *   2. WC product short description (single product).
	 *   3. Post excerpt (any singular).
	 *   4. WC term description (taxonomy archive).
	 *   5. Site tagline (Settings → General → Tagline).
	 *   6. Restaurant Information description (Customizer panel) — final fallback
	 *      so the homepage gets a meaningful <meta name="description"> even when
	 *      the operator hasn't set a tagline yet. Without this, fresh installs
	 *      ship with no meta description at all, capping Lighthouse SEO ≤92.
	 *   7. Constructed local-business pitch from name + servedCuisine + locality.
	 */
	function lafka_resolve_meta_description( $post_or_null ) {
		if ( $post_or_null ) {
			$override = get_post_meta( $post_or_null->ID, '_lafka_meta_description', true );
			if ( $override ) {
				return $override;
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				$product = wc_get_product( $post_or_null->ID );
				if ( $product && $product->get_short_description() ) {
					return wp_strip_all_tags( $product->get_short_description() );
				}
			}
			if ( ! empty( $post_or_null->post_excerpt ) ) {
				return wp_strip_all_tags( $post_or_null->post_excerpt );
			}
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->description ) ) {
				return wp_strip_all_tags( $term->description );
			}
		}
		$tagline = get_bloginfo( 'description' );
		if ( $tagline ) {
			// WP defaults the tagline to either "Just another WordPress site"
			// or the site name on some installs. Either case produces a
			// useless meta description that competes with — and loses to —
			// the Restaurant Information description the operator can
			// configure. Skip the tagline when it's the default WP boilerplate
			// or a verbatim duplicate of the site name, so the next
			// fallback gets a chance.
			$site_name      = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			$is_wp_default  = 'Just another WordPress site' === $tagline;
			$is_dupe_of_nam = '' !== $site_name && 0 === strcasecmp( trim( $tagline ), trim( $site_name ) );
			if ( ! $is_wp_default && ! $is_dupe_of_nam ) {
				return $tagline;
			}
		}
		if ( function_exists( 'lafka_get_restaurant_info' ) ) {
			$info = lafka_get_restaurant_info();
			if ( ! empty( $info['description'] ) ) {
				return wp_strip_all_tags( $info['description'] );
			}
			// Construct a sensible auto-pitch when the operator has set NAP but
			// not a description: "{name} — fresh {cuisine} in {locality}".
			$bits = array();
			if ( ! empty( $info['name'] ) ) {
				$bits[] = $info['name'];
			}
			// Read the flat keys that lafka_get_restaurant_info() actually
			// returns (`cuisines`, `city`). The legacy code looked for
			// `servedCuisine` and `address.addressLocality`, which never
			// existed in the array — making the pitch always collapse to
			// just `name`. Fixed in v9.22.1.
			$cuisine = '';
			if ( ! empty( $info['cuisines'] ) ) {
				$cuisine = is_array( $info['cuisines'] )
					? implode( ', ', array_map( 'strval', $info['cuisines'] ) )
					: (string) $info['cuisines'];
			}
			$locality = ! empty( $info['city'] ) ? (string) $info['city'] : '';
			if ( $cuisine && $locality ) {
				$bits[] = sprintf(
					/* translators: 1: cuisine list, 2: city/locality */
					__( 'Fresh %1$s in %2$s — order online or call.', 'lafka-plugin' ),
					$cuisine,
					$locality
				);
			} elseif ( $locality ) {
				$bits[] = sprintf(
					/* translators: %s: city/locality */
					__( 'Serving %s — order online or call.', 'lafka-plugin' ),
					$locality
				);
			}
			if ( ! empty( $bits ) ) {
				return implode( ' — ', $bits );
			}
		}
		return '';
	}
}
