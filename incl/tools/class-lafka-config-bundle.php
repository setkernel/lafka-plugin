<?php
/**
 * Lafka_Config_Bundle — versioned export/import of a configured install (NX1-05).
 *
 * Moves a fully-configured Lafka install between environments (staging→prod,
 * demo→customer) as a single self-describing JSON bundle. The only prior tool
 * was the one-way restaurant-info CLI seeder; this is the round-trippable,
 * per-section replacement that the NX3 demo packs and NX5 "sell a configured
 * vertical" both ride on.
 *
 * ── Envelope ────────────────────────────────────────────────────────────────
 *   {
 *     "schema_version": 1,
 *     "generated_at":   "2026-07-06T12:00:00+00:00",
 *     "site_url":       "https://source.example",
 *     "plugin_version": "9.36.0",
 *     "manifest": { "excluded": [ "…human-readable notes…" ] },
 *     "sections": { "flags": {…}, "business": {…}, … }
 *   }
 *
 * ── Sections (each with its own export/import/validate) ──────────────────────
 *   flags          — the 'lafka' option array (feature toggles), secrets stripped.
 *   business       — discrete lafka_business_* / hero / contact / share options.
 *   numeric_knobs  — promotions levers (free-delivery, first-order, slow-day, combo).
 *   order_hours    — the lafka_order_hours_options array.
 *   shipping_areas — the four lafka_shipping_areas_* option groups (secrets stripped).
 *   theme_mods     — ONLY lafka_-prefixed, non-secret theme mods (feature flags).
 *   branches       — lafka_branch_location terms + all per-branch term meta.
 *   areas          — lafka_shipping_areas CPT posts incl. polygon meta.
 *   addon_groups   — lafka_glb_addon CPT posts + their addon meta (raw post+meta).
 *
 * ── Excluded by design (surfaced in the manifest as "configure manually") ────
 *   - KDS options + token (live-kitchen constraint — never rewritten by a bundle).
 *   - Web-push subscriptions + abandoned-cart tables (personal data, not portable).
 *   - Secrets + analytics: Google Maps API key, web-push VAPID keys, and ALL
 *     analytics/tracking IDs (GA4/GTM/Clarity/Meta/CF beacon). These are
 *     destination-specific and must be re-entered on the target site.
 *
 * ── Import contract ─────────────────────────────────────────────────────────
 *   - Create/update only, NEVER delete — an import can only add or overwrite.
 *   - Per-section validators reject malformed data: a malformed KNOWN section
 *     fails loudly (report ok=false, section not applied); an UNKNOWN section is
 *     skipped with a warning.
 *   - Term/post sections match by slug/title so a re-import is idempotent (a
 *     second import of the same bundle writes nothing — all "skipped").
 *   - addon_groups is stored as raw post + meta (the engine repository hydrates
 *     value objects, which we deliberately bypass to keep the export lossless and
 *     mock-friendly); its _product_addons array round-trips verbatim.
 *
 * Pure, side-effect-free class methods (no hooks registered at include time) so
 * the whole round-trip is unit-testable via Brain Monkey without booting WP.
 * The WP-CLI (incl/cli/lafka-config-cli.php) and admin Tools screen
 * (incl/admin/class-lafka-tools-page.php) are thin surfaces over this class.
 *
 * @package Lafka\Plugin\Tools
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Config_Bundle' ) ) {

	final class Lafka_Config_Bundle {

		/**
		 * Bundle envelope schema version. Bump only on a breaking shape change;
		 * import rejects any other version.
		 */
		const SCHEMA_VERSION = 1;

		/** Taxonomy holding branch terms. */
		const BRANCH_TAXONOMY = 'lafka_branch_location';

		/** CPT holding delivery-zone polygons. */
		const AREA_POST_TYPE = 'lafka_shipping_areas';

		/** CPT holding global add-on groups. */
		const ADDON_POST_TYPE = 'lafka_glb_addon';

		// ─── Section registry ────────────────────────────────────────────────

		/**
		 * Every section id, in export order. Each id `foo` is backed by
		 * export_foo() / validate_foo() / import_foo() methods.
		 *
		 * @return array<int,string>
		 */
		public static function section_ids(): array {
			return array(
				'flags',
				'business',
				'numeric_knobs',
				'order_hours',
				'shipping_areas',
				'theme_mods',
				'branches',
				'areas',
				'addon_groups',
			);
		}

		// ─── Export ──────────────────────────────────────────────────────────

		/**
		 * Build the full, secret-stripped bundle envelope.
		 *
		 * @return array<string,mixed>
		 */
		public static function export(): array {
			$sections = array();
			foreach ( self::section_ids() as $id ) {
				$sections[ $id ] = call_user_func( array( __CLASS__, 'export_' . $id ) );
			}

			return array(
				'schema_version' => self::SCHEMA_VERSION,
				'generated_at'   => gmdate( 'c' ),
				'site_url'       => function_exists( 'home_url' ) ? home_url() : '',
				'plugin_version' => defined( 'LAFKA_PLUGIN_VERSION' ) ? LAFKA_PLUGIN_VERSION : '',
				'manifest'       => array( 'excluded' => self::excluded_notes() ),
				'sections'       => $sections,
			);
		}

		/**
		 * Export the bundle as pretty-printed JSON.
		 *
		 * @return string
		 */
		public static function export_json(): string {
			$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
			$data  = self::export();
			if ( function_exists( 'wp_json_encode' ) ) {
				return (string) wp_json_encode( $data, $flags );
			}
			return (string) json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pre-WP fallback only.
		}

		/**
		 * Human-readable notes describing what the bundle intentionally omits,
		 * surfaced to the operator on both export and import.
		 *
		 * @return array<int,string>
		 */
		public static function excluded_notes(): array {
			return array(
				__( 'Kitchen display (KDS) options and access tokens are excluded — the live-kitchen tablet must not be re-pointed by a config import. Configure KDS manually on the destination.', 'lafka-plugin' ),
				__( 'Web-push subscriptions and abandoned-cart records are excluded — they are personal data tied to this site and are not portable.', 'lafka-plugin' ),
				__( 'Secrets and analytics IDs are excluded — Google Maps API key, web-push VAPID keys, and every GA4 / GTM / Clarity / Meta / Cloudflare tracking ID. Re-enter these on the destination site.', 'lafka-plugin' ),
			);
		}

		// ─── Import ──────────────────────────────────────────────────────────

		/**
		 * Decode a JSON bundle and import it.
		 *
		 * @param string $json    Bundle JSON.
		 * @param bool   $dry_run When true, compute counts but write nothing.
		 * @return array<string,mixed> Report (see import()).
		 */
		public static function import_json( string $json, bool $dry_run = false ): array {
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				return self::error_report( $dry_run, __( 'The bundle is not valid JSON.', 'lafka-plugin' ) );
			}
			return self::import( $data, $dry_run );
		}

		/**
		 * Import a decoded bundle: create/update only, never delete.
		 *
		 * @param array<string,mixed> $bundle  Decoded envelope.
		 * @param bool                $dry_run When true, compute counts but write nothing.
		 * @return array{ok:bool,dry_run:bool,sections:array<string,array{created:int,updated:int,skipped:int}>,warnings:array<int,string>,errors:array<int,string>}
		 */
		public static function import( array $bundle, bool $dry_run = false ): array {
			$report = array(
				'ok'       => true,
				'dry_run'  => $dry_run,
				'sections' => array(),
				'warnings' => array(),
				'errors'   => array(),
			);

			$version = $bundle['schema_version'] ?? null;
			if ( self::SCHEMA_VERSION !== $version ) {
				$report['ok']       = false;
				$report['errors'][] = sprintf(
					/* translators: 1: bundle schema version, 2: supported schema version. */
					__( 'Unsupported bundle schema version %1$s (this plugin imports version %2$s).', 'lafka-plugin' ),
					is_scalar( $version ) ? (string) $version : gettype( $version ),
					(string) self::SCHEMA_VERSION
				);
				return $report;
			}

			$sections = $bundle['sections'] ?? array();
			if ( ! is_array( $sections ) ) {
				$report['ok']       = false;
				$report['errors'][] = __( 'The bundle has no valid "sections" object.', 'lafka-plugin' );
				return $report;
			}

			// Pass 1 — classify + validate every section BEFORE writing anything.
			// Unknown sections are a warning (skipped); a malformed KNOWN section
			// is a hard, loud failure that blocks the whole import so a partial
			// section can never be applied silently.
			$known    = self::section_ids();
			$to_apply = array();
			foreach ( $sections as $id => $data ) {
				if ( ! in_array( $id, $known, true ) ) {
					$report['warnings'][] = sprintf(
						/* translators: %s: section id. */
						__( 'Unknown section "%s" skipped.', 'lafka-plugin' ),
						is_scalar( $id ) ? (string) $id : gettype( $id )
					);
					continue;
				}

				$valid = call_user_func( array( __CLASS__, 'validate_' . $id ), $data );
				if ( true !== $valid ) {
					$report['ok']       = false;
					$report['errors'][] = sprintf(
						/* translators: 1: section id, 2: reason. */
						__( 'Section "%1$s" is malformed: %2$s', 'lafka-plugin' ),
						$id,
						is_string( $valid ) ? $valid : __( 'invalid data', 'lafka-plugin' )
					);
					continue;
				}

				$to_apply[ $id ] = $data;
			}

			// A malformed known section fails the whole bundle — nothing is applied.
			if ( ! $report['ok'] ) {
				return $report;
			}

			// Pass 2 — apply the validated sections (create/update only).
			foreach ( $to_apply as $id => $data ) {
				$report['sections'][ $id ] = call_user_func(
					array( __CLASS__, 'import_' . $id ),
					$data,
					$dry_run
				);
			}

			return $report;
		}

		// ─── Section: flags (the 'lafka' option array) ───────────────────────

		/**
		 * @return array<string,mixed>
		 */
		public static function export_flags(): array {
			$flags = get_option( 'lafka', array() );
			if ( ! is_array( $flags ) ) {
				return array();
			}
			return self::strip_secret_keys( $flags );
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_flags( $data ) {
			return self::validate_scalar_map( $data, __( 'flags must be a map of scalar values', 'lafka-plugin' ) );
		}

		/**
		 * @param array<string,mixed> $data    Incoming flags.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_flags( array $data, bool $dry_run ): array {
			return self::merge_array_option( 'lafka', self::strip_secret_keys( $data ), $dry_run, true );
		}

		// ─── Section: business (discrete NAP / schema / hero options) ─────────

		/**
		 * Discrete option names owned by the "business" section. Enumerated from
		 * the WooCommerce Restaurant settings tab + the schema resolver's
		 * lafka_business_* reads (incl/schema/lafka-schema-helpers.php).
		 *
		 * @return array<int,string>
		 */
		public static function business_option_keys(): array {
			return array(
				'lafka_business_name',
				'lafka_business_street',
				'lafka_business_city',
				'lafka_business_region',
				'lafka_business_postal',
				'lafka_business_country',
				'lafka_business_phone_e164',
				'lafka_business_phone_display',
				'lafka_business_email',
				'lafka_business_geo_lat',
				'lafka_business_geo_lng',
				'lafka_business_price_range',
				'lafka_business_business_type',
				'lafka_business_cuisines',
				'lafka_business_payment_methods',
				'lafka_business_same_as',
				'lafka_business_hours_mon',
				'lafka_business_hours_tue',
				'lafka_business_hours_wed',
				'lafka_business_hours_thu',
				'lafka_business_hours_fri',
				'lafka_business_hours_sat',
				'lafka_business_hours_sun',
				'lafka_homepage_hero_image',
				'lafka_homepage_hero_attachment_id',
				'lafka_contact_phone',
				'lafka_share_on_posts',
				'lafka_share_on_products',
			);
		}

		/**
		 * @return array<string,mixed>
		 */
		public static function export_business(): array {
			return self::export_option_list( self::business_option_keys() );
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_business( $data ) {
			return self::validate_scalar_map( $data, __( 'business must be a map of option values', 'lafka-plugin' ) );
		}

		/**
		 * @param array<string,mixed> $data    Incoming options.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_business( array $data, bool $dry_run ): array {
			return self::import_option_list( $data, self::business_option_keys(), $dry_run );
		}

		// ─── Section: numeric_knobs (promotions levers) ──────────────────────

		/**
		 * @return array<int,string>
		 */
		public static function numeric_knob_keys(): array {
			return array(
				'lafka_free_delivery_threshold',
				'lafka_first_order_discount_percent',
				'lafka_slow_day_discount_percent',
				'lafka_slow_day_days',
				'lafka_combo_deal_cat_a',
				'lafka_combo_deal_cat_b',
				'lafka_combo_deal_amount',
				'lafka_combo_deal_type',
			);
		}

		/**
		 * @return array<string,mixed>
		 */
		public static function export_numeric_knobs(): array {
			return self::export_option_list( self::numeric_knob_keys() );
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_numeric_knobs( $data ) {
			return self::validate_scalar_map( $data, __( 'numeric_knobs must be a map of option values', 'lafka-plugin' ) );
		}

		/**
		 * @param array<string,mixed> $data    Incoming options.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_numeric_knobs( array $data, bool $dry_run ): array {
			return self::import_option_list( $data, self::numeric_knob_keys(), $dry_run );
		}

		// ─── Section: order_hours (single array option) ──────────────────────

		/**
		 * @return array<string,mixed>
		 */
		public static function export_order_hours(): array {
			$opts = get_option( 'lafka_order_hours_options', array() );
			return is_array( $opts ) ? $opts : array();
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_order_hours( $data ) {
			if ( ! is_array( $data ) ) {
				return __( 'order_hours must be an object', 'lafka-plugin' );
			}
			return true;
		}

		/**
		 * @param array<string,mixed> $data    Incoming schedule.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_order_hours( array $data, bool $dry_run ): array {
			return self::merge_array_option( 'lafka_order_hours_options', $data, $dry_run, false );
		}

		// ─── Section: shipping_areas (four option groups) ────────────────────

		/**
		 * @return array<int,string>
		 */
		public static function shipping_area_groups(): array {
			return array(
				'lafka_shipping_areas_general',
				'lafka_shipping_areas_advanced',
				'lafka_shipping_areas_datetime',
				'lafka_shipping_areas_branches',
			);
		}

		/**
		 * @return array<string,array<string,mixed>>
		 */
		public static function export_shipping_areas(): array {
			$out = array();
			foreach ( self::shipping_area_groups() as $group ) {
				$value = get_option( $group, array() );
				if ( ! is_array( $value ) || empty( $value ) ) {
					continue;
				}
				$out[ $group ] = self::strip_secret_keys( $value );
			}
			return $out;
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_shipping_areas( $data ) {
			if ( ! is_array( $data ) ) {
				return __( 'shipping_areas must be an object of option groups', 'lafka-plugin' );
			}
			$groups = self::shipping_area_groups();
			foreach ( $data as $group => $value ) {
				if ( ! in_array( $group, $groups, true ) ) {
					return sprintf(
						/* translators: %s: option-group name. */
						__( 'unknown shipping option group "%s"', 'lafka-plugin' ),
						is_scalar( $group ) ? (string) $group : gettype( $group )
					);
				}
				if ( ! is_array( $value ) ) {
					return sprintf(
						/* translators: %s: option-group name. */
						__( 'shipping option group "%s" must be an object', 'lafka-plugin' ),
						(string) $group
					);
				}
			}
			return true;
		}

		/**
		 * @param array<string,mixed> $data    Incoming groups.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_shipping_areas( array $data, bool $dry_run ): array {
			$counts = self::zero_counts();
			foreach ( self::shipping_area_groups() as $group ) {
				if ( ! isset( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
					continue;
				}
				$incoming = self::strip_secret_keys( $data[ $group ] );
				$c        = self::merge_array_option( $group, $incoming, $dry_run, false );
				$counts   = self::add_counts( $counts, $c );
			}
			return $counts;
		}

		// ─── Section: theme_mods (lafka_-prefixed, non-secret only) ──────────

		/**
		 * @return array<string,mixed>
		 */
		public static function export_theme_mods(): array {
			$mods = function_exists( 'get_theme_mods' ) ? get_theme_mods() : array();
			if ( ! is_array( $mods ) ) {
				return array();
			}
			$out = array();
			foreach ( $mods as $key => $value ) {
				if ( 0 !== strpos( (string) $key, 'lafka_' ) ) {
					continue;
				}
				if ( self::is_secret_theme_mod( (string) $key ) ) {
					continue;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_theme_mods( $data ) {
			if ( ! is_array( $data ) ) {
				return __( 'theme_mods must be an object', 'lafka-plugin' );
			}
			foreach ( array_keys( $data ) as $key ) {
				if ( 0 !== strpos( (string) $key, 'lafka_' ) ) {
					return sprintf(
						/* translators: %s: theme mod key. */
						__( 'theme mod "%s" is not namespaced lafka_ and was rejected', 'lafka-plugin' ),
						is_scalar( $key ) ? (string) $key : gettype( $key )
					);
				}
			}
			return true;
		}

		/**
		 * @param array<string,mixed> $data    Incoming theme mods.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_theme_mods( array $data, bool $dry_run ): array {
			$counts = self::zero_counts();
			foreach ( $data as $key => $value ) {
				$key = (string) $key;
				if ( 0 !== strpos( $key, 'lafka_' ) || self::is_secret_theme_mod( $key ) ) {
					continue;
				}
				$current = get_theme_mod( $key, self::sentinel() );
				if ( self::values_equal( $current, $value ) ) {
					++$counts['skipped'];
					continue;
				}
				$created = ( self::sentinel() === $current );
				if ( ! $dry_run ) {
					set_theme_mod( $key, $value );
				}
				++$counts[ $created ? 'created' : 'updated' ];
			}
			return $counts;
		}

		// ─── Section: branches (terms + all per-branch term meta) ────────────

		/**
		 * Per-branch term-meta keys (enumerated from
		 * incl/branches/class-lafka-branch-locations*.php). branch_id is the
		 * internal linkage id shipping-area assignments reference; preserved
		 * verbatim (portable within a site, best-effort across sites).
		 *
		 * @return array<int,string>
		 */
		public static function branch_term_meta_keys(): array {
			return array(
				'branch_id',
				'lafka_branch_order_type',
				'lafka_branch_user',
				'lafka_branch_delivery_time',
				'lafka_branch_location_img_id',
				'lafka_branch_address',
				'lafka_branch_address_geocoded',
				'lafka_branch_distance_restriction',
				'lafka_branch_distance_unit',
				'lafka_branch_shipping_areas',
				'lafka_branch_timezone',
				'lafka_branch_override_datetime_global',
				'lafka_branch_datetime_mandatory',
				'lafka_branch_datetime_days_ahead',
				'lafka_branch_datetime_timeslot_duration',
				'lafka_branch_datetime_orders_per_timeslot',
				'lafka_branch_override_order_hours_global',
				'lafka_branch_order_hours_schedule',
				'lafka_branch_order_hours_force_override_check',
				'lafka_branch_order_hours_force_override_status',
				'lafka_branch_order_hours_holidays_calendar',
			);
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public static function export_branches(): array {
			$terms = get_terms(
				array(
					'taxonomy'   => self::BRANCH_TAXONOMY,
					'hide_empty' => false,
				)
			);
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) {
				return array();
			}
			if ( ! is_array( $terms ) ) {
				return array();
			}

			$out = array();
			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || ! isset( $term->slug ) ) {
					continue;
				}
				$meta = array();
				foreach ( self::branch_term_meta_keys() as $key ) {
					$value = get_term_meta( $term->term_id, $key, true );
					if ( '' === $value || null === $value ) {
						continue;
					}
					$meta[ $key ] = $value;
				}
				$out[] = array(
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
					'meta' => $meta,
				);
			}
			return $out;
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_branches( $data ) {
			return self::validate_record_list( $data, 'slug', __( 'branches', 'lafka-plugin' ) );
		}

		/**
		 * @param array<int,array<string,mixed>> $data    Incoming branch records.
		 * @param bool                           $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_branches( array $data, bool $dry_run ): array {
			$counts = self::zero_counts();
			foreach ( $data as $record ) {
				$slug = (string) ( $record['slug'] ?? '' );
				$name = (string) ( $record['name'] ?? $slug );
				$meta = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
				if ( '' === $slug ) {
					continue;
				}

				$term    = get_term_by( 'slug', $slug, self::BRANCH_TAXONOMY );
				$created = false;
				$term_id = 0;

				if ( $term && isset( $term->term_id ) ) {
					$term_id = (int) $term->term_id;
				} elseif ( $dry_run ) {
					// Nothing persisted yet — count as a create, no meta to diff.
					++$counts['created'];
					continue;
				} else {
					$inserted = wp_insert_term( $name, self::BRANCH_TAXONOMY, array( 'slug' => $slug ) );
					if ( function_exists( 'is_wp_error' ) && is_wp_error( $inserted ) ) {
						continue;
					}
					$term_id = (int) ( is_array( $inserted ) ? ( $inserted['term_id'] ?? 0 ) : 0 );
					$created = true;
				}

				$changed = self::apply_meta( $term_id, $meta, 'term', $dry_run );
				if ( $created ) {
					++$counts['created'];
				} elseif ( $changed ) {
					++$counts['updated'];
				} else {
					++$counts['skipped'];
				}
			}
			return $counts;
		}

		// ─── Section: areas (shipping-zone CPT + polygon meta) ───────────────

		/**
		 * @return array<int,string>
		 */
		public static function area_post_meta_keys(): array {
			return array( '_lafka_shipping_area_polygon_coordinates' );
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public static function export_areas(): array {
			return self::export_posts( self::AREA_POST_TYPE, self::area_post_meta_keys() );
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_areas( $data ) {
			return self::validate_record_list( $data, 'title', __( 'areas', 'lafka-plugin' ) );
		}

		/**
		 * @param array<int,array<string,mixed>> $data    Incoming area records.
		 * @param bool                           $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_areas( array $data, bool $dry_run ): array {
			return self::import_posts( $data, self::AREA_POST_TYPE, self::area_post_meta_keys(), $dry_run );
		}

		// ─── Section: addon_groups (global add-on CPT + addon meta) ──────────

		/**
		 * Raw post-meta keys carrying an add-on group's configuration. The
		 * _product_addons array round-trips verbatim (we bypass the engine
		 * repository's value-object hydration by design — see class docblock).
		 *
		 * @return array<int,string>
		 */
		public static function addon_post_meta_keys(): array {
			return array(
				'_product_addons',
				'_all_products',
				'_priority',
				'_product_addons_exclude_global',
			);
		}

		/**
		 * @return array<int,array<string,mixed>>
		 */
		public static function export_addon_groups(): array {
			return self::export_posts( self::ADDON_POST_TYPE, self::addon_post_meta_keys() );
		}

		/**
		 * @param mixed $data Section payload.
		 * @return true|string
		 */
		public static function validate_addon_groups( $data ) {
			return self::validate_record_list( $data, 'title', __( 'addon_groups', 'lafka-plugin' ) );
		}

		/**
		 * @param array<int,array<string,mixed>> $data    Incoming addon records.
		 * @param bool                           $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		public static function import_addon_groups( array $data, bool $dry_run ): array {
			return self::import_posts( $data, self::ADDON_POST_TYPE, self::addon_post_meta_keys(), $dry_run );
		}

		// ─── Shared post export/import ───────────────────────────────────────

		/**
		 * @param string             $post_type CPT slug.
		 * @param array<int,string>  $meta_keys Meta keys to carry.
		 * @return array<int,array<string,mixed>>
		 */
		private static function export_posts( string $post_type, array $meta_keys ): array {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'numberposts'    => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'suppress_filters' => false,
				)
			);
			if ( ! is_array( $posts ) ) {
				return array();
			}

			$out = array();
			foreach ( $posts as $post ) {
				if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
					continue;
				}
				$meta = array();
				foreach ( $meta_keys as $key ) {
					$value = get_post_meta( $post->ID, $key, true );
					if ( '' === $value || null === $value ) {
						continue;
					}
					$meta[ $key ] = $value;
				}
				$out[] = array(
					'title'  => (string) $post->post_title,
					'status' => (string) ( $post->post_status ?? 'publish' ),
					'meta'   => $meta,
				);
			}
			return $out;
		}

		/**
		 * @param array<int,array<string,mixed>> $data      Incoming records.
		 * @param string                         $post_type CPT slug.
		 * @param array<int,string>              $meta_keys Meta keys to carry.
		 * @param bool                           $dry_run   Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		private static function import_posts( array $data, string $post_type, array $meta_keys, bool $dry_run ): array {
			$counts = self::zero_counts();
			foreach ( $data as $record ) {
				$title = (string) ( $record['title'] ?? '' );
				$meta  = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
				$status = (string) ( $record['status'] ?? 'publish' );
				if ( '' === $title ) {
					continue;
				}

				$existing = get_posts(
					array(
						'post_type'   => $post_type,
						'title'       => $title,
						'post_status' => 'any',
						'numberposts' => 1,
					)
				);
				$post    = ( is_array( $existing ) && ! empty( $existing ) ) ? $existing[0] : null;
				$created = false;
				$post_id = 0;

				if ( $post && isset( $post->ID ) ) {
					$post_id = (int) $post->ID;
				} elseif ( $dry_run ) {
					++$counts['created'];
					continue;
				} else {
					$post_id = (int) wp_insert_post(
						array(
							'post_title'  => $title,
							'post_type'   => $post_type,
							'post_status' => $status,
						)
					);
					if ( $post_id <= 0 ) {
						continue;
					}
					$created = true;
				}

				// Only carry the whitelisted meta keys present on the record.
				$to_write = array();
				foreach ( $meta_keys as $key ) {
					if ( array_key_exists( $key, $meta ) ) {
						$to_write[ $key ] = $meta[ $key ];
					}
				}
				$changed = self::apply_meta( $post_id, $to_write, 'post', $dry_run );

				if ( $created ) {
					++$counts['created'];
				} elseif ( $changed ) {
					++$counts['updated'];
				} else {
					++$counts['skipped'];
				}
			}
			return $counts;
		}

		// ─── Shared helpers ──────────────────────────────────────────────────

		/**
		 * Read a list of discrete options into a payload, skipping unset ones.
		 *
		 * @param array<int,string> $keys Option names.
		 * @return array<string,mixed>
		 */
		private static function export_option_list( array $keys ): array {
			$out = array();
			foreach ( $keys as $key ) {
				$value = get_option( $key, self::sentinel() );
				if ( self::sentinel() === $value || null === $value ) {
					continue;
				}
				$out[ $key ] = $value;
			}
			return $out;
		}

		/**
		 * Write a whitelisted list of discrete options (create/update/skip).
		 *
		 * @param array<string,mixed> $data    Incoming values.
		 * @param array<int,string>   $allowed Whitelisted option names.
		 * @param bool                $dry_run Preview only.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		private static function import_option_list( array $data, array $allowed, bool $dry_run ): array {
			$counts = self::zero_counts();
			foreach ( $allowed as $key ) {
				if ( ! array_key_exists( $key, $data ) ) {
					continue;
				}
				$incoming = $data[ $key ];
				$current  = get_option( $key, self::sentinel() );
				if ( self::values_equal( $current, $incoming ) ) {
					++$counts['skipped'];
					continue;
				}
				$created = ( self::sentinel() === $current );
				if ( ! $dry_run ) {
					update_option( $key, $incoming );
				}
				++$counts[ $created ? 'created' : 'updated' ];
			}
			return $counts;
		}

		/**
		 * Merge an incoming array into an existing array-typed option
		 * (create/update/skip as one unit). Never removes existing keys.
		 *
		 * @param string              $option    Option name.
		 * @param array<string,mixed> $incoming  Incoming array.
		 * @param bool                $dry_run   Preview only.
		 * @param bool                $flush_lafka_options Bust Lafka_Options cache after write.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		private static function merge_array_option( string $option, array $incoming, bool $dry_run, bool $flush_lafka_options ): array {
			$counts  = self::zero_counts();
			$current = get_option( $option, self::sentinel() );
			$existed = ( self::sentinel() !== $current );
			$base    = ( $existed && is_array( $current ) ) ? $current : array();

			$merged = array_merge( $base, $incoming );

			if ( $existed && self::values_equal( $base, $merged ) ) {
				++$counts['skipped'];
				return $counts;
			}

			if ( ! $dry_run ) {
				update_option( $option, $merged );
				if ( $flush_lafka_options && class_exists( 'Lafka_Options' ) ) {
					Lafka_Options::flush();
				}
			}
			++$counts[ $existed ? 'updated' : 'created' ];
			return $counts;
		}

		/**
		 * Apply a map of meta values to a term or post, returning whether any
		 * value actually changed. Never deletes meta.
		 *
		 * @param int                 $object_id Term or post id.
		 * @param array<string,mixed> $meta      key => value.
		 * @param string              $type      'term' | 'post'.
		 * @param bool                $dry_run   Preview only.
		 * @return bool Whether any value differed from what was stored.
		 */
		private static function apply_meta( int $object_id, array $meta, string $type, bool $dry_run ): bool {
			$changed = false;
			foreach ( $meta as $key => $value ) {
				$current = ( 'term' === $type )
					? get_term_meta( $object_id, $key, true )
					: get_post_meta( $object_id, $key, true );
				if ( self::values_equal( $current, $value ) ) {
					continue;
				}
				$changed = true;
				if ( ! $dry_run ) {
					if ( 'term' === $type ) {
						update_term_meta( $object_id, $key, $value );
					} else {
						update_post_meta( $object_id, $key, $value );
					}
				}
			}
			return $changed;
		}

		/**
		 * @return array{created:int,updated:int,skipped:int}
		 */
		private static function zero_counts(): array {
			return array(
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
			);
		}

		/**
		 * @param array{created:int,updated:int,skipped:int} $a First counts.
		 * @param array{created:int,updated:int,skipped:int} $b Second counts.
		 * @return array{created:int,updated:int,skipped:int}
		 */
		private static function add_counts( array $a, array $b ): array {
			return array(
				'created' => $a['created'] + $b['created'],
				'updated' => $a['updated'] + $b['updated'],
				'skipped' => $a['skipped'] + $b['skipped'],
			);
		}

		/**
		 * Loose value comparison that is stable across the JSON round-trip
		 * (json turns everything into strings/arrays), so a re-import of the
		 * same bundle reads as unchanged.
		 *
		 * @param mixed $a First value.
		 * @param mixed $b Second value.
		 * @return bool
		 */
		private static function values_equal( $a, $b ): bool {
			if ( is_array( $a ) && is_array( $b ) ) {
				return self::stable_json( $a ) === self::stable_json( $b );
			}
			if ( is_scalar( $a ) && is_scalar( $b ) ) {
				return (string) $a === (string) $b;
			}
			return $a === $b;
		}

		/**
		 * Stable JSON for value comparison: arrays are recursively key-sorted so
		 * array key order can't produce a spurious "changed" verdict when the
		 * same bundle is imported twice.
		 *
		 * @param mixed $value Value to encode.
		 * @return string
		 */
		private static function stable_json( $value ): string {
			if ( is_array( $value ) ) {
				$value = self::ksort_recursive( $value );
			}
			if ( function_exists( 'wp_json_encode' ) ) {
				return (string) wp_json_encode( $value );
			}
			return (string) json_encode( $value );
		}

		/**
		 * Recursively key-sort an array for deterministic serialization.
		 *
		 * @param array<mixed> $data Array to sort.
		 * @return array<mixed>
		 */
		private static function ksort_recursive( array $data ): array {
			foreach ( $data as $key => $value ) {
				if ( is_array( $value ) ) {
					$data[ $key ] = self::ksort_recursive( $value );
				}
			}
			ksort( $data );
			return $data;
		}

		/**
		 * A unique sentinel distinguishing "option absent" from a stored false/''.
		 *
		 * @return string
		 */
		private static function sentinel(): string {
			return "\0lafka_config_bundle_absent\0";
		}

		/**
		 * Validate that a payload is a map of scalar/array option values.
		 *
		 * @param mixed  $data    Payload.
		 * @param string $message Failure message.
		 * @return true|string
		 */
		private static function validate_scalar_map( $data, string $message ) {
			if ( ! is_array( $data ) ) {
				return $message;
			}
			return true;
		}

		/**
		 * Validate that a payload is a list of records each carrying $key_field.
		 *
		 * @param mixed  $data      Payload.
		 * @param string $key_field Required identity field ('slug' | 'title').
		 * @param string $label     Section label for the message.
		 * @return true|string
		 */
		private static function validate_record_list( $data, string $key_field, string $label ) {
			if ( ! is_array( $data ) ) {
				return sprintf(
					/* translators: %s: section label. */
					__( '%s must be a list of records', 'lafka-plugin' ),
					$label
				);
			}
			foreach ( $data as $record ) {
				if ( ! is_array( $record ) ) {
					return sprintf(
						/* translators: %s: section label. */
						__( 'every %s record must be an object', 'lafka-plugin' ),
						$label
					);
				}
				if ( ! isset( $record[ $key_field ] ) || '' === (string) $record[ $key_field ] ) {
					return sprintf(
						/* translators: 1: field name, 2: section label. */
						__( 'every %2$s record needs a non-empty "%1$s"', 'lafka-plugin' ),
						$key_field,
						$label
					);
				}
			}
			return true;
		}

		// ─── Secret handling ─────────────────────────────────────────────────

		/**
		 * Strip every secret / analytics key out of an otherwise-portable array
		 * option (the 'lafka' flags array + the shipping option groups). Uses the
		 * shared is_secret_key() predicate so a secret smuggled in under ANY of
		 * the recognised names — not just Google-Maps-style *api_key* keys — is
		 * shed on export and never re-applied on import.
		 *
		 * @param array<string,mixed> $data Array to strip.
		 * @return array<string,mixed>
		 */
		private static function strip_secret_keys( array $data ): array {
			foreach ( array_keys( $data ) as $key ) {
				if ( self::is_secret_key( (string) $key ) ) {
					unset( $data[ $key ] );
				}
			}
			return $data;
		}

		/**
		 * Single source of truth for "this key names a secret / analytics
		 * identifier that must never travel in a config bundle". Shared by
		 * strip_secret_keys() (array-typed flags + shipping options) and
		 * is_secret_theme_mod() (the theme_mods section) so both shed the same
		 * union of patterns. Deliberately aggressive: the whole analytics /
		 * tracking namespace is destination-specific and excluded by design.
		 *
		 * @param string $key Option, array, or theme-mod key.
		 * @return bool
		 */
		private static function is_secret_key( string $key ): bool {
			$secret_prefixes = array(
				'lafka_analytics_',
				'lafka_push_vapid',
				'lafka_ga4_',
				'lafka_gtm_',
				'lafka_clarity_',
				'lafka_meta_pixel',
				'lafka_cf_beacon',
			);
			foreach ( $secret_prefixes as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					return true;
				}
			}
			$secret_substrings = array( 'vapid', 'api_key', 'api_secret', '_secret', '_token', 'pixel_id', 'measurement_id', 'container_id' );
			foreach ( $secret_substrings as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether a theme_mod key is a secret / analytics identifier. Thin
		 * wrapper over the shared is_secret_key() predicate.
		 *
		 * @param string $key Theme mod key.
		 * @return bool
		 */
		private static function is_secret_theme_mod( string $key ): bool {
			return self::is_secret_key( $key );
		}

		/**
		 * Build a failure report for a decode/precondition error.
		 *
		 * @param bool   $dry_run Whether this was a dry-run.
		 * @param string $message Error message.
		 * @return array<string,mixed>
		 */
		private static function error_report( bool $dry_run, string $message ): array {
			return array(
				'ok'       => false,
				'dry_run'  => $dry_run,
				'sections' => array(),
				'warnings' => array(),
				'errors'   => array( $message ),
			);
		}
	}
}
