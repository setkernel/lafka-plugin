<?php
/**
 * Plugin behaviour settings: one home, one getter, one migration.
 *
 * Settings that change what the plugin DOES (abandoned-cart email, web push,
 * review requests, checkout guards, SEO defaults, …) are plugin options, not
 * theme_mods. Theme_mods are stored per stylesheet, so a theme or child-theme
 * switch silently dropped them, and an uninstall never found them. The
 * Customizer controls stay (registered with 'type' => 'option', like the
 * Analytics panel) but the value lives in wp_options under the same key.
 *
 *   lafka_setting( $key, $default )  the one reader.
 *   lafka_settings_keys()            every key moved off theme_mods (single list).
 *   lafka_settings_maybe_migrate()   one-time copy theme_mod → option, then remove.
 *
 * @package Lafka\Plugin\Settings
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Version of the migration below. Bump when the key list grows so the copy
 * runs once more (it never overwrites an option that already exists).
 */
const LAFKA_SETTINGS_VERSION = '1';

if ( ! function_exists( 'lafka_settings_keys' ) ) {
	/**
	 * Every setting stored as a plugin option (Customizer 'type' => 'option').
	 * Dynamic families are covered by lafka_settings_key_prefixes().
	 *
	 * @since 10.4.0
	 * @return string[]
	 */
	function lafka_settings_keys(): array {
		return array(
			// Abandoned cart.
			'lafka_ac_enabled',
			'lafka_ac_delay_minutes',
			'lafka_ac_global_opt_out',
			'lafka_ac_subject',
			'lafka_ac_intro_heading',
			'lafka_ac_intro_body',
			'lafka_ac_cta_label',
			// Web push.
			'lafka_push_enabled',
			'lafka_push_vapid_subject',
			'lafka_push_vapid_public_key',
			'lafka_push_vapid_private_key',
			'lafka_push_subscribe_prompt_enabled',
			'lafka_push_subscribe_prompt_threshold',
			'lafka_push_subscribe_prompt_copy',
			'lafka_push_reorder_reminder_enabled',
			'lafka_push_reorder_reminder_days',
			// Review requests.
			'lafka_review_email_enabled',
			'lafka_review_email_delay_hours',
			'lafka_review_email_subject',
			'lafka_review_email_intro',
			'lafka_review_target_url',
			'lafka_review_target_label',
			'lafka_review_banner_enabled',
			'lafka_review_banner_window_days',
			'lafka_review_banner_copy',
			'lafka_review_banner_cta_label',
			// Product page behaviour.
			'lafka_pdp_redesign_enabled',
			'lafka_pdp_show_bestseller_eyebrow',
			'lafka_pdp_prep_time_default',
			'lafka_sort_variation_options',
			// Checkout.
			'lafka_delivery_quote_guard',
			'lafka_delivery_quote_guard_message',
			'lafka_cod_contextual_title',
			'lafka_cod_title_pickup',
			'lafka_cod_description_pickup',
			'lafka_cod_title_delivery',
			'lafka_cod_description_delivery',
			'lafka_pickup_checkout_slim',
			// SEO.
			'lafka_default_locale',
			'lafka_og_image_default',
		);
	}
}

if ( ! function_exists( 'lafka_settings_key_prefixes' ) ) {
	/**
	 * Key families (one option per member): the per-category upsell picks, prep-time overrides and the contact FAQ.
	 *
	 * @since 10.4.0
	 * @return string[]
	 */
	function lafka_settings_key_prefixes(): array {
		return array( 'lafka_upsell_', 'lafka_pdp_prep_time_', 'lafka_contact_faq_' );
	}
}

if ( ! function_exists( 'lafka_settings_secret_keys' ) ) {
	/**
	 * Settings that hold a secret: stored without autoload, never shown in the
	 * Customizer, never exported in a config bundle.
	 *
	 * @since 10.4.0
	 * @return string[]
	 */
	function lafka_settings_secret_keys(): array {
		return array( 'lafka_push_vapid_private_key' );
	}
}

if ( ! function_exists( 'lafka_setting' ) ) {
	/**
	 * Read one plugin setting.
	 *
	 * @since 10.4.0
	 * @param string $key           Option name (see lafka_settings_keys()).
	 * @param mixed  $default_value Returned when the option is not stored.
	 * @return mixed
	 */
	function lafka_setting( string $key, $default_value = '' ) {
		return get_option( $key, $default_value );
	}
}

if ( ! function_exists( 'lafka_settings_is_key' ) ) {
	/**
	 * Whether a name belongs to the moved settings (list or dynamic family).
	 *
	 * @since 10.4.0
	 * @param string $key Name.
	 * @return bool
	 */
	function lafka_settings_is_key( string $key ): bool {
		if ( in_array( $key, lafka_settings_keys(), true ) ) {
			return true;
		}
		foreach ( lafka_settings_key_prefixes() as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_settings_retired_keys' ) ) {
	/**
	 * Theme mods of features that no longer exist; the migration removes them.
	 * (The win-back email field collected addresses nothing ever used.)
	 *
	 * @since 10.4.0
	 * @return string[]
	 */
	function lafka_settings_retired_keys(): array {
		return array( 'lafka_pdp_winback_offer_text' );
	}
}

if ( ! function_exists( 'lafka_settings_merged_keys' ) ) {
	/**
	 * Theme mods that had a second home for a fact that has one now: old
	 * theme_mod => the option that carries it.
	 *
	 * @since 10.4.0
	 * @return array<string,string>
	 */
	function lafka_settings_merged_keys(): array {
		return array(
			// The free-delivery minimum is set under WooCommerce → Settings → Restaurant → Promotions.
			'lafka_pdp_free_delivery_threshold' => 'lafka_free_delivery_threshold',
			// The deals category is a menu fact the plugin owns (lafka_get_deals_category_id()).
			'lafka_counter_deals_cat'           => 'lafka_deals_category',
		);
	}
}

if ( ! function_exists( 'lafka_settings_migrate' ) ) {
	/**
	 * Copy the moved settings out of the theme_mods of the active theme (and
	 * its parent) into options, then remove them from the theme_mods. An option
	 * that already exists is never overwritten. The child's value wins over the
	 * parent's.
	 *
	 * @since 10.4.0
	 * @return string[] Keys that were moved.
	 */
	function lafka_settings_migrate(): array {
		$stylesheet = get_stylesheet();
		$template   = get_template();
		$names      = array_unique( array( 'theme_mods_' . $stylesheet, 'theme_mods_' . $template ) );
		$sets       = array();
		foreach ( $names as $name ) {
			$mods          = get_option( $name, array() );
			$sets[ $name ] = is_array( $mods ) ? $mods : array();
		}

		$keys = array();
		foreach ( $sets as $mods ) {
			foreach ( array_keys( $mods ) as $key ) {
				if ( is_string( $key ) && lafka_settings_is_key( $key ) ) {
					$keys[ $key ] = true;
				}
			}
		}

		$secrets  = lafka_settings_secret_keys();
		$sentinel = new stdClass();
		$moved    = array();
		foreach ( array_keys( $keys ) as $key ) {
			$value = null;
			foreach ( $names as $name ) {
				if ( array_key_exists( $key, $sets[ $name ] ) ) {
					$value = $sets[ $name ][ $key ];
					break;
				}
			}
			$stored = get_option( $key, $sentinel );
			if ( $sentinel === $stored ) {
				add_option( $key, $value, '', in_array( $key, $secrets, true ) ? 'no' : 'yes' );
			}
			$moved[] = $key;
			foreach ( $names as $name ) {
				unset( $sets[ $name ][ $key ] );
			}
		}

		foreach ( $names as $name ) {
			foreach ( lafka_settings_retired_keys() as $retired ) {
				if ( array_key_exists( $retired, $sets[ $name ] ) ) {
					unset( $sets[ $name ][ $retired ] );
					$moved[] = $retired;
				}
			}
		}

		// A setting that merged into another home: the value moves there unless
		// that already has one.
		foreach ( lafka_settings_merged_keys() as $legacy_key => $option ) {
			foreach ( $names as $name ) {
				if ( ! isset( $sets[ $name ][ $legacy_key ] ) ) {
					continue;
				}
				$legacy = (float) $sets[ $name ][ $legacy_key ];
				if ( $legacy > 0 && (float) get_option( $option, 0 ) <= 0 ) {
					update_option( $option, $sets[ $name ][ $legacy_key ] );
				}
				unset( $sets[ $name ][ $legacy_key ] );
				$moved[] = $legacy_key;
			}
		}

		if ( $moved ) {
			foreach ( $names as $name ) {
				update_option( $name, $sets[ $name ] );
			}
		}
		return array_values( array_unique( $moved ) );
	}
}

if ( ! function_exists( 'lafka_settings_maybe_migrate' ) ) {
	/**
	 * Run the one-time migrations once per LAFKA_SETTINGS_VERSION.
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_settings_maybe_migrate(): void {
		if ( LAFKA_SETTINGS_VERSION === get_option( 'lafka_settings_version', '' ) ) {
			return;
		}
		lafka_settings_migrate();
		lafka_settings_drop_legacy_flags();
		if ( false !== get_option( 'lafka_tracking', false ) ) {
			lafka_tracking_keep_out_of_autoload();
		}
		update_option( 'lafka_settings_version', LAFKA_SETTINGS_VERSION );
	}
	add_action( 'plugins_loaded', 'lafka_settings_maybe_migrate', 1 );
}

if ( ! function_exists( 'lafka_settings_drop_legacy_flags' ) ) {
	/**
	 * Tidy the `lafka` array: remove keys of features that no longer exist (the
	 * product promo tooltips, the product pop-up and the category-description
	 * position had no screen to set them) and the stale seeded top-bar phone.
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_settings_drop_legacy_flags(): void {
		$flags = get_option( 'lafka', null );
		if ( ! is_array( $flags ) ) {
			return;
		}
		$changed = false;
		// The security-headers switch used to be kept in this array; its home is
		// lafka_security_options.
		if ( isset( $flags['enable_security_headers'] ) ) {
			$security = get_option( 'lafka_security_options', array() );
			$security = is_array( $security ) ? $security : array();
			if ( ! isset( $security['enable_security_headers'] ) ) {
				$security['enable_security_headers'] = $flags['enable_security_headers'];
				update_option( 'lafka_security_options', $security );
			}
			unset( $flags['enable_security_headers'] );
			$changed = true;
		}
		foreach ( array_keys( $flags ) as $key ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'promo_tooltip_' )
				|| in_array( $key, array( 'custom_product_popup_link', 'custom_product_popup_content', 'category_description_position', 'top_bar_message_phone' ), true ) ) {
				unset( $flags[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( 'lafka', $flags );
			if ( class_exists( 'Lafka_Options' ) ) {
				Lafka_Options::flush();
			}
		}
	}
}

if ( ! function_exists( 'lafka_settings_legacy_theme_mod' ) ) {
	/**
	 * Back-compat for code that still calls get_theme_mod() on a moved setting
	 * (child-theme snippets, call sites not yet switched to lafka_setting()):
	 * the old theme_mod now answers with the option's value.
	 *
	 * @since 10.4.0
	 * @deprecated 10.4.0 Read the setting with lafka_setting(). Removed in the next major version.
	 *
	 * @param mixed $value Theme mod value (or the caller's default).
	 * @return mixed
	 */
	function lafka_settings_legacy_theme_mod( $value ) {
		$name     = str_replace( 'theme_mod_', '', (string) current_filter() );
		$sentinel = new stdClass();
		$stored   = get_option( $name, $sentinel );
		return $sentinel === $stored ? $value : $stored;
	}
	foreach ( lafka_settings_keys() as $lafka_settings_key ) {
		add_filter( 'theme_mod_' . $lafka_settings_key, 'lafka_settings_legacy_theme_mod' );
	}
	unset( $lafka_settings_key );
}
