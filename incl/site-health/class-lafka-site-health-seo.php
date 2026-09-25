<?php
/**
 * GX3: "Search & AI" checks in Tools → Site Health → Status.
 *
 * Every check is a plain collector that returns human-readable issues (unit
 * tested) plus a thin Site Health result wrapper:
 *
 *   lafka_seo_nap       — the business record: the two legacy stores
 *                         (option vs Customizer theme_mod) disagree, the
 *                         literal "Array" stored for a list, a street line
 *                         that is really the business name, a raw E.164
 *                         number as the display phone.
 *   lafka_seo_profile   — missing sameAs profiles, review link, tagline,
 *                         description, map link; site language that does
 *                         not match the store country (en_US for a CA store).
 *   lafka_seo_indexing  — legacy food-menu posts still published, attribute
 *                         archives (pa_*) someone filtered back into the index.
 *   lafka_seo_content   — products without a short description, categories
 *                         without a description, product photos without a
 *                         WebP version (counts).
 *   lafka_seo_indexnow  — IndexNow off (hint) or its last ping failing.
 *
 * All "recommended" (orange) — nothing here breaks the site.
 *
 * @package Lafka\Plugin\SiteHealth
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Site_Health_Seo' ) ) {

	/**
	 * Search & AI Site Health checks.
	 */
	final class Lafka_Site_Health_Seo {

		/** Transient caching the (filesystem-heavy) content-gap counts. */
		const GAPS_TRANSIENT = 'lafka_seo_health_gaps';

		/** Most product photos checked for a WebP sibling per run. */
		const WEBP_SAMPLE = 300;

		/**
		 * Register the checks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'site_status_tests', array( __CLASS__, 'register' ) );
		}

		/**
		 * `site_status_tests` callback.
		 *
		 * @param array<string,array> $tests Tests.
		 * @return array<string,array>
		 */
		public static function register( $tests ) {
			$checks = array(
				'lafka_seo_nap'      => array( __( 'Lafka business info', 'lafka-plugin' ), 'test_nap' ),
				'lafka_seo_profile'  => array( __( 'Lafka business profile', 'lafka-plugin' ), 'test_profile' ),
				'lafka_seo_indexing' => array( __( 'Lafka indexing hygiene', 'lafka-plugin' ), 'test_indexing' ),
				'lafka_seo_content'  => array( __( 'Lafka menu content', 'lafka-plugin' ), 'test_content' ),
				'lafka_seo_indexnow' => array( __( 'Lafka IndexNow', 'lafka-plugin' ), 'test_indexnow' ),
			);
			foreach ( $checks as $id => $check ) {
				$tests['direct'][ $id ] = array(
					'label' => esc_html( $check[0] ),
					'test'  => array( __CLASS__, $check[1] ),
				);
			}
			return $tests;
		}

		// ─── Collectors (pure: return issue strings) ─────────────────────

		/**
		 * Normalise a stored value for comparison by field.
		 *
		 * @param string $key   Field suffix.
		 * @param mixed  $value Stored value.
		 * @return string
		 */
		private static function comparable( string $key, $value ): string {
			$value = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
			if ( 0 === strpos( $key, 'phone_' ) ) {
				return (string) preg_replace( '/\D/', '', $value );
			}
			return strtolower( trim( (string) preg_replace( '/\s+/', ' ', $value ) ) );
		}

		/**
		 * Distance in metres between two coordinates (haversine).
		 *
		 * @param float $lat1 Latitude 1.
		 * @param float $lng1 Longitude 1.
		 * @param float $lat2 Latitude 2.
		 * @param float $lng2 Longitude 2.
		 * @return float
		 */
		public static function distance_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
			$r    = 6371000.0;
			$dlat = deg2rad( $lat2 - $lat1 );
			$dlng = deg2rad( $lng2 - $lng1 );
			$a    = sin( $dlat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $dlng / 2 ) ** 2;
			return 2 * $r * asin( min( 1.0, sqrt( $a ) ) );
		}

		/**
		 * Whether a street line is really the business name ("Acme Kitchen"
		 * typed into Address line 1, the street pushed to line 2).
		 *
		 * @param string $street Street line.
		 * @param string $name   Business name.
		 * @return bool
		 */
		public static function street_looks_like_name( string $street, string $name ): bool {
			$norm = static function ( string $s ): string {
				$s = strtolower( str_replace( '&', ' and ', $s ) );
				return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
			};
			$street = $norm( $street );
			$name   = $norm( $name );
			if ( '' === $street || '' === $name ) {
				return false;
			}
			if ( $street === $name ) {
				return true;
			}
			if ( preg_match( '/\d/', $street ) ) {
				return false; // A real street line almost always carries a number.
			}
			$s_words = array_filter( explode( ' ', $street ), static fn( $w ) => strlen( $w ) > 2 );
			$n_words = array_filter( explode( ' ', $name ), static fn( $w ) => strlen( $w ) > 2 );
			if ( empty( $s_words ) || empty( $n_words ) ) {
				return false;
			}
			return count( array_intersect( $s_words, $n_words ) ) / count( $s_words ) >= 0.6;
		}

		/**
		 * Business-record problems.
		 *
		 * @return list<string>
		 */
		public static function nap_issues(): array {
			$issues = array();
			$keys   = function_exists( 'lafka_nap_field_keys' ) ? lafka_nap_field_keys() : array();

			foreach ( $keys as $key ) {
				$name   = 'lafka_business_' . $key;
				$option = get_option( $name, null );
				$legacy = get_theme_mod( $name, null );

				foreach ( array( $option, $legacy ) as $stored ) {
					if ( is_string( $stored ) && 0 === strcasecmp( trim( $stored ), 'Array' ) ) {
						/* translators: %s: setting name. */
						$issues[] = sprintf( __( '%s holds the literal text "Array" (a bug in an old version) — re-enter it.', 'lafka-plugin' ), $name );
						break;
					}
				}

				if ( ! lafka_schema_is_set_value( $option ) || ! lafka_schema_is_set_value( $legacy ) ) {
					continue;
				}
				if ( 'geo_lat' === $key || 'geo_lng' === $key ) {
					continue; // Compared as a pair below.
				}
				if ( self::comparable( $key, $option ) !== self::comparable( $key, $legacy ) ) {
					$issues[] = sprintf(
						/* translators: 1: setting name, 2: value in use, 3: stale Customizer value. */
						__( '%1$s: the site uses "%2$s" but an old Customizer value says "%3$s". Confirm which is right, then remove the stale one (wp theme mod remove %1$s).', 'lafka-plugin' ),
						$name,
						is_array( $option ) ? implode( ', ', $option ) : (string) $option,
						is_array( $legacy ) ? implode( ', ', $legacy ) : (string) $legacy
					);
				}
			}

			$o_lat = get_option( 'lafka_business_geo_lat', null );
			$o_lng = get_option( 'lafka_business_geo_lng', null );
			$t_lat = get_theme_mod( 'lafka_business_geo_lat', null );
			$t_lng = get_theme_mod( 'lafka_business_geo_lng', null );
			if ( is_numeric( $o_lat ) && is_numeric( $o_lng ) && is_numeric( $t_lat ) && is_numeric( $t_lng ) ) {
				$metres = self::distance_m( (float) $o_lat, (float) $o_lng, (float) $t_lat, (float) $t_lng );
				if ( $metres > 50 ) {
					$issues[] = sprintf(
						/* translators: %d: distance in metres. */
						__( 'Map coordinates: the site uses one pin but an old Customizer value is %d m away. Use your Google Business Profile pin.', 'lafka-plugin' ),
						(int) round( $metres )
					);
				}
			}

			$info  = lafka_get_restaurant_info();
			$wc_l1 = (string) get_option( 'woocommerce_store_address', '' );
			foreach ( array_unique( array_filter( array( (string) ( $info['street'] ?? '' ), $wc_l1 ) ) ) as $street ) {
				if ( self::street_looks_like_name( $street, (string) ( $info['name'] ?? '' ) ) ) {
					/* translators: %s: street line. */
					$issues[] = sprintf( __( 'The street address "%s" looks like the business name — put the street (number + road) in Address line 1 (WooCommerce → Settings → General).', 'lafka-plugin' ), $street );
				}
			}

			$display = (string) get_option( 'lafka_business_phone_display', '' );
			if ( function_exists( 'lafka_phone_is_bare_number' ) && lafka_phone_is_bare_number( $display ) ) {
				$issues[] = sprintf(
					/* translators: 1: stored display phone, 2: formatted phone. */
					__( 'The display phone is stored as a machine number (%1$s). It is shown as %2$s automatically; set "Phone (display format)" to the exact format you want.', 'lafka-plugin' ),
					$display,
					(string) ( $info['phone_display'] ?? '' )
				);
			}

			return $issues;
		}

		/**
		 * Missing profile facts + locale mismatch.
		 *
		 * @return list<string>
		 */
		public static function profile_issues(): array {
			$issues = array();
			$info   = lafka_get_restaurant_info();

			if ( empty( $info['same_as'] ) ) {
				$issues[] = __( 'No social / listing profiles (sameAs). Add your Google Business Profile, Facebook, Instagram, Yelp … under WooCommerce → Settings → Restaurant → Social Profiles.', 'lafka-plugin' );
			}
			if ( '' === trim( (string) get_theme_mod( 'lafka_review_target_url', '' ) ) ) {
				$issues[] = __( 'No review link. Set your Google "write a review" URL in Customizer → Reviews so post-order emails can ask for reviews.', 'lafka-plugin' );
			}
			$tagline = trim( (string) get_bloginfo( 'description' ) );
			if ( '' === $tagline || 'Just another WordPress site' === $tagline ) {
				$issues[] = __( 'No site tagline (Settings → General). A short "what & where" line, e.g. "Wood-fired pizza in Springfield", helps search results.', 'lafka-plugin' );
			}
			if ( empty( $info['description'] ) ) {
				$issues[] = __( 'No restaurant description (WooCommerce → Settings → Restaurant → Schema & Geo). It feeds structured data and /llms.txt.', 'lafka-plugin' );
			}
			if ( empty( $info['map_url'] ) ) {
				$issues[] = __( 'No Google Maps / Business Profile link (Restaurant → Schema & Geo → Map URL).', 'lafka-plugin' );
			}

			$locale  = (string) get_locale();
			$country = strtoupper( (string) strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) );
			$pinned  = (string) get_theme_mod( 'lafka_default_locale', '' );
			if ( '' === $pinned && '' !== $country && preg_match( '/^([a-z]{2,3})_([A-Z]{2})$/', $locale, $m ) && $m[2] !== $country ) {
				$issues[] = sprintf(
					/* translators: 1: site locale, 2: store country, 3: suggested locale. */
					__( 'The site language is %1$s but the store is in %2$s — switch Settings → General → Site Language to %3$s (or pin it in Customizer → Social Sharing) so search engines see the right region.', 'lafka-plugin' ),
					$locale,
					$country,
					$m[1] . '_' . $country
				);
			}

			return $issues;
		}

		/**
		 * URLs that should not be in search.
		 *
		 * @return list<string>
		 */
		public static function indexing_issues(): array {
			$issues = array();

			foreach ( function_exists( 'lafka_seo_legacy_post_types' ) ? lafka_seo_legacy_post_types() : array() as $type ) {
				if ( ! post_type_exists( $type ) ) {
					continue;
				}
				$counts    = wp_count_posts( $type );
				$published = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
				if ( $published > 0 ) {
					$issues[] = sprintf(
						/* translators: 1: number of posts, 2: post type. */
						_n( '%1$d legacy "%2$s" entry is published. Lafka keeps it out of the sitemap and noindexes it — trash it if you no longer use it.', '%1$d legacy "%2$s" entries are published. Lafka keeps them out of the sitemap and noindexes them — trash them if you no longer use them.', $published, 'lafka-plugin' ),
						$published,
						$type
					);
				}
			}

			$excluded = function_exists( 'lafka_seo_excluded_taxonomies' ) ? lafka_seo_excluded_taxonomies() : array();
			foreach ( (array) get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
				if ( 0 === strpos( (string) $taxonomy, 'pa_' ) && ! in_array( $taxonomy, $excluded, true ) ) {
					/* translators: %s: taxonomy name. */
					$issues[] = sprintf( __( 'Attribute archive "%s" is indexable (it was filtered back in). Attribute pages list products by size/option and compete with the menu.', 'lafka-plugin' ), $taxonomy );
				}
			}

			return $issues;
		}

		/**
		 * Content-gap counts (cached for an hour — the WebP scan stats files).
		 *
		 * @return array{products_no_short:int,categories_no_desc:int,images_not_webp:int,images_checked:int}
		 */
		public static function content_gaps(): array {
			$cached = get_transient( self::GAPS_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['products_no_short'] ) ) {
				return $cached;
			}
			global $wpdb;

			$products_no_short = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_excerpt = ''",
					'product',
					'publish'
				)
			);

			$categories_no_desc = 0;
			$terms              = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
				)
			);
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( is_object( $term ) && 'uncategorized' !== $term->slug && '' === trim( (string) $term->description ) ) {
					++$categories_no_desc;
				}
			}

			$thumb_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s LIMIT %d",
					'_thumbnail_id',
					'product',
					'publish',
					self::WEBP_SAMPLE
				)
			);
			$not_webp = 0;
			$checked  = 0;
			foreach ( (array) $thumb_ids as $id ) {
				$file = (string) get_attached_file( (int) $id );
				if ( '' === $file ) {
					continue;
				}
				++$checked;
				if ( preg_match( '/\.webp$/i', $file ) ) {
					continue;
				}
				if ( ! file_exists( (string) preg_replace( '/\.(png|jpe?g)$/i', '.webp', $file ) ) ) {
					++$not_webp;
				}
			}

			$gaps = array(
				'products_no_short'  => $products_no_short,
				'categories_no_desc' => $categories_no_desc,
				'images_not_webp'    => $not_webp,
				'images_checked'     => $checked,
			);
			set_transient( self::GAPS_TRANSIENT, $gaps, HOUR_IN_SECONDS );
			return $gaps;
		}

		/**
		 * Content gaps as issue strings.
		 *
		 * @param array{products_no_short:int,categories_no_desc:int,images_not_webp:int,images_checked:int} $gaps Counts.
		 * @return list<string>
		 */
		public static function content_issues( array $gaps ): array {
			$issues = array();
			if ( $gaps['products_no_short'] > 0 ) {
				$issues[] = sprintf(
					/* translators: %d: number of products. */
					_n( '%d product has no short description — it falls back to a generic line in search results and AI answers.', '%d products have no short description — they fall back to a generic line in search results and AI answers.', $gaps['products_no_short'], 'lafka-plugin' ),
					$gaps['products_no_short']
				);
			}
			if ( $gaps['categories_no_desc'] > 0 ) {
				$issues[] = sprintf(
					/* translators: %d: number of categories. */
					_n( '%d menu category has no description (80–150 words of real detail makes the category page rank for it).', '%d menu categories have no description (80–150 words of real detail makes each category page rank for it).', $gaps['categories_no_desc'], 'lafka-plugin' ),
					$gaps['categories_no_desc']
				);
			}
			if ( $gaps['images_not_webp'] > 0 ) {
				$issues[] = sprintf(
					/* translators: 1: images without WebP, 2: images checked. */
					__( '%1$d of %2$d product photos have no WebP version — run `wp lafka images convert-webp` to cut image weight.', 'lafka-plugin' ),
					$gaps['images_not_webp'],
					$gaps['images_checked']
				);
			}
			return $issues;
		}

		// ─── Site Health results ─────────────────────────────────────────

		/**
		 * Build a Site Health result from issues.
		 *
		 * @param string       $test    Test id.
		 * @param string       $good    Label when clean.
		 * @param string       $bad     Label with issues.
		 * @param list<string> $issues  Issues.
		 * @param string       $explain Intro paragraph.
		 * @return array<string,mixed>
		 */
		public static function result( string $test, string $good, string $bad, array $issues, string $explain ): array {
			$description = '<p>' . esc_html( $explain ) . '</p>';
			if ( ! empty( $issues ) ) {
				$description .= '<ul>';
				foreach ( $issues as $issue ) {
					$description .= '<li>' . esc_html( $issue ) . '</li>';
				}
				$description .= '</ul>';
			}
			return array(
				'label'       => esc_html( empty( $issues ) ? $good : $bad ),
				'status'      => empty( $issues ) ? 'good' : 'recommended',
				'badge'       => array(
					'label' => esc_html__( 'Search & AI', 'lafka-plugin' ),
					'color' => empty( $issues ) ? 'blue' : 'orange',
				),
				'description' => $description,
				'actions'     => '',
				'test'        => $test,
			);
		}

		/** @return array<string,mixed> */
		public static function test_nap() {
			return self::result(
				'lafka_seo_nap',
				__( 'Business name, address and phone are consistent', 'lafka-plugin' ),
				__( 'Business name, address or phone need attention', 'lafka-plugin' ),
				self::nap_issues(),
				__( 'Search engines and AI assistants trust a business whose name, address and phone read the same everywhere.', 'lafka-plugin' )
			);
		}

		/** @return array<string,mixed> */
		public static function test_profile() {
			return self::result(
				'lafka_seo_profile',
				__( 'Business profile is complete', 'lafka-plugin' ),
				__( 'Business profile is missing details', 'lafka-plugin' ),
				self::profile_issues(),
				__( 'These facts feed the structured data, /llms.txt and local search.', 'lafka-plugin' )
			);
		}

		/** @return array<string,mixed> */
		public static function test_indexing() {
			return self::result(
				'lafka_seo_indexing',
				__( 'No thin or legacy pages are indexable', 'lafka-plugin' ),
				__( 'Some legacy or thin pages need cleaning up', 'lafka-plugin' ),
				self::indexing_issues(),
				__( 'Leftover demo pages and attribute archives dilute the pages that should rank: the menu, its categories and products.', 'lafka-plugin' )
			);
		}

		/** @return array<string,mixed> */
		public static function test_content() {
			return self::result(
				'lafka_seo_content',
				__( 'Menu content is complete', 'lafka-plugin' ),
				__( 'Menu content has gaps', 'lafka-plugin' ),
				self::content_issues( self::content_gaps() ),
				__( 'Descriptions and light images are what search results and AI answers quote.', 'lafka-plugin' )
			);
		}

		/** @return array<string,mixed> */
		public static function test_indexnow() {
			$issues = array();
			$enabled = function_exists( 'lafka_indexnow_enabled' ) && lafka_indexnow_enabled();
			if ( ! $enabled ) {
				$issues[] = __( 'IndexNow is off. Turn it on (WooCommerce → Settings → Restaurant → Search & AI) so Bing — which feeds ChatGPT search and Copilot — sees menu and price changes within minutes.', 'lafka-plugin' );
			} else {
				$last = get_option( 'lafka_seo_indexnow_last', array() );
				$code = is_array( $last ) ? (int) ( $last['code'] ?? 0 ) : 0;
				if ( is_array( $last ) && ! empty( $last['time'] ) && ( $code < 200 || $code >= 300 ) ) {
					/* translators: %d: HTTP status code. */
					$issues[] = sprintf( __( 'The last IndexNow ping failed (HTTP %d). It retries with the next change.', 'lafka-plugin' ), $code );
				}
			}
			return self::result(
				'lafka_seo_indexnow',
				__( 'IndexNow is on', 'lafka-plugin' ),
				$enabled ? __( 'IndexNow needs attention', 'lafka-plugin' ) : __( 'IndexNow could announce your changes', 'lafka-plugin' ),
				$issues,
				__( 'IndexNow pings search engines when your menu changes (production sites only).', 'lafka-plugin' )
			);
		}
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		Lafka_Site_Health_Seo::init();
	}
}
