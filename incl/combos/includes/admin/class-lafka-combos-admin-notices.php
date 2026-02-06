<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin notices handling.
 *
 * @class    WC_LafkaCombos_Admin_Notices
 * @version  6.7.2
 */
class WC_LafkaCombos_Admin_Notices {

	/**
	 * Notices presisting on the next request.
	 * @var array
	 */
	public static $meta_box_notices = array();

	/**
	 * Notices displayed on the current request.
	 * @var array
	 */
	public static $admin_notices = array();

	/**
	 * Maintenance notices displayed on every request until cleared.
	 * @var array
	 */
	public static $maintenance_notices = array();

	/**
	 * Dismissible notices displayed on the current request.
	 * @var array
	 */
	public static $dismissed_notices = array();

	/**
	 * Constructor.
	 */
	public static function init() {

		if ( ! class_exists( 'WC_LafkaCombos_Notices' ) ) {
			require_once  WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-notices.php' ;
		}

		// Avoid duplicates for some notice types that are meant to be unique.
		if ( ! isset( $GLOBALS[ 'sw_store' ][ 'notices_unique' ] ) ) {
			$GLOBALS[ 'sw_store' ][ 'notices_unique' ] = array();
		}

		self::$maintenance_notices = get_option( 'wc_pb_maintenance_notices', array() );
		self::$dismissed_notices   = get_user_meta( get_current_user_id(), 'wc_pb_dismissed_notices', true );
		self::$dismissed_notices   = empty( self::$dismissed_notices ) ? array() : self::$dismissed_notices;

		// Show meta box notices.
		add_action( 'admin_notices', array( __CLASS__, 'output_notices' ) );
		// Save meta box notices.
		add_action( 'shutdown', array( __CLASS__, 'save_notices' ), 100 );
	}

	/**
	 * Add a notice/error.
	 *
	 * @param  string   $text
	 * @param  mixed    $args
	 * @param  boolean  $save_notice
	 */
	public static function add_notice( $text, $args, $save_notice = false ) {

		if ( is_array( $args ) ) {
			$type           = $args[ 'type' ];
			$dismiss_class  = isset( $args[ 'dismiss_class' ] ) ? $args[ 'dismiss_class' ] : false;
			$unique_context = isset( $args[ 'unique_context' ] ) ? $args[ 'unique_context' ] : false;
			$save_notice    = isset( $args[ 'save_notice' ] ) ? $args[ 'save_notice' ] : $save_notice;
		} else {
			$type           = $args;
			$dismiss_class  = false;
			$unique_context = false;
		}

		if ( $unique_context ) {
			if ( self::unique_notice_exists( $unique_context ) ) {
				return;
			} else {
				$GLOBALS[ 'sw_store' ][ 'notices_unique' ][] = $unique_context;
			}
		}

		$notice = array(
			'type'          => $type,
			'content'       => $text,
			'dismiss_class' => $dismiss_class
		);

		if ( $save_notice ) {
			self::$meta_box_notices[] = $notice;
		} else {
			self::$admin_notices[] = $notice;
		}
	}

	/**
	 * Checks if a notice that belongs to a the specified uniqueness context already exists.
	 *
	 * @since  6.3.0
	 *
	 * @param  string  $context
	 * @return bool
	 */
	private static function unique_notice_exists( $context ) {
		return $context && in_array( $context, $GLOBALS[ 'sw_store' ][ 'notices_unique' ] );
	}

	/**
	 * Get a setting for a notice type.
	 *
	 * @since  6.3.0
	 *
	 * @param  string  $notice_name
	 * @param  string  $key
	 * @param  mixed   $default
	 * @return array
	 */
	public static function get_notice_option( $notice_name, $key, $default = null ) {
		return WC_LafkaCombos_Notices::get_notice_option( $notice_name, $key, $default );
	}

	/**
	 * Set a setting for a notice type.
	 *
	 * @since  6.3.0
	 *
	 * @param  string  $notice_name
	 * @param  string  $key
	 * @param  mixed   $value
	 * @return array
	 */
	public static function set_notice_option( $notice_name, $key, $value ) {
		return WC_LafkaCombos_Notices::set_notice_option( $notice_name, $key, $value );
	}

	/**
	 * Checks if a maintenance notice is visible.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $notice_name
	 * @return boolean
	 */
	public static function is_maintenance_notice_visible( $notice_name ) {
		return in_array( $notice_name, self::$maintenance_notices );
	}

	/**
	 * Checks if a dismissible notice has been dismissed in the past.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $notice_name
	 * @return boolean
	 */
	public static function is_dismissible_notice_dismissed( $notice_name ) {
		return in_array( $notice_name, self::$dismissed_notices );
	}

	/**
	 * Save notices to the DB.
	 */
	public static function save_notices() {
		update_option( 'wc_pb_meta_box_notices', self::$meta_box_notices );
		update_option( 'wc_pb_maintenance_notices', self::$maintenance_notices );
	}

	/**
	 * Show any stored error messages.
	 */
	public static function output_notices() {

		$saved_notices = get_option( 'wc_pb_meta_box_notices', array() );
		$notices       = $saved_notices + self::$admin_notices;

		if ( ! empty( $notices ) ) {

			foreach ( $notices as $notice ) {

				$notice_classes = array( 'wc_pb_notice', 'notice', 'notice-' . $notice[ 'type' ] );
				$dismiss_attr   = $notice[ 'dismiss_class' ] ? 'data-dismiss_class="' . $notice[ 'dismiss_class' ] . '"' : '';

				if ( $notice[ 'dismiss_class' ] ) {
					$notice_classes[] = $notice[ 'dismiss_class' ];
					$notice_classes[] = 'is-dismissible';
				}

				echo '<div class="' . implode( ' ', $notice_classes ) . '"' . $dismiss_attr . '>';
				echo wpautop( wp_kses_post( $notice[ 'content' ] ) );
				echo '</div>';
			}

			if ( function_exists( 'wc_enqueue_js' ) ) {
				wc_enqueue_js( "
					jQuery( function( $ ) {
						jQuery( '.wc_pb_notice .notice-dismiss' ).on( 'click', function() {

							var data = {
								action: 'woocommerce_dismiss_combo_notice',
								notice: jQuery( this ).parent().data( 'dismiss_class' ),
								security: '" . wp_create_nonce( 'wc_pb_dismiss_notice_nonce' ) . "'
							};

							jQuery.post( '" . WC()->ajax_url() . "', data );
						} );
					} );
				" );
			}

			// Clear.
			delete_option( 'wc_pb_meta_box_notices' );
		}
	}

	/**
	 * Add a dimissible notice/error.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $text
	 * @param  mixed   $args
	 */
	public static function add_dismissible_notice( $text, $args ) {
		if ( ! isset( $args[ 'dismiss_class' ] ) || ! self::is_dismissible_notice_dismissed( $args[ 'dismiss_class' ] ) ) {
			self::add_notice( $text, $args );
		}
	}

	/**
	 * Remove a dismissible notice.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $notice_name
	 */
	public static function remove_dismissible_notice( $notice_name ) {

		// Remove if not already removed.
		if ( ! self::is_dismissible_notice_dismissed( $notice_name ) ) {
			self::$dismissed_notices = array_merge( self::$dismissed_notices, array( $notice_name ) );
			update_user_meta( get_current_user_id(), 'wc_pb_dismissed_notices', self::$dismissed_notices );
			return true;
		}

		return false;
	}

	/**
	 * Add a maintenance notice to be displayed.
	 *
	 * @param  string  $notice_name
	 */
	public static function add_maintenance_notice( $notice_name ) {

		// Add if not already there.
		if ( ! self::is_maintenance_notice_visible( $notice_name ) ) {
			self::$maintenance_notices = array_merge( self::$maintenance_notices, array( $notice_name ) );
			return true;
		}

		return false;
	}

	/**
	 * Remove a maintenance notice.
	 *
	 * @param  string  $notice_name
	 */
	public static function remove_maintenance_notice( $notice_name ) {

		// Remove if there.
		if ( self::is_maintenance_notice_visible( $notice_name ) ) {
			self::$maintenance_notices = array_diff( self::$maintenance_notices, array( $notice_name ) );
			return true;
		}

		return false;
	}

	/**
	 * Returns a "trigger update" notice component.
	 *
	 * @since  5.5.0
	 *
	 * @return string
	 */
	private static function get_trigger_update_prompt() {
		$update_url    = esc_url( wp_nonce_url( add_query_arg( 'trigger_wc_pb_db_update', true, admin_url() ), 'wc_pb_trigger_db_update_nonce', '_wc_pb_admin_nonce' ) );
		$update_prompt = '<p><a href="' . $update_url . '" class="wc-pb-update-now button">' . __( 'Update database', 'lafka-plugin' ) . '</a></p>';
		return $update_prompt;
	}

	/**
	 * Returns a "force update" notice component.
	 *
	 * @since  5.5.0
	 *
	 * @return string
	 */
	private static function get_force_update_prompt() {

		$fallback_prompt = '';
		$update_runtime  = get_option( 'wc_pb_update_init', 0 );

		// Wait for at least 30 seconds.
		if ( gmdate( 'U' ) - $update_runtime > 30 ) {
			// Perhaps the upgrade process failed to start?
			$fallback_url    = esc_url( wp_nonce_url( add_query_arg( 'force_wc_pb_db_update', true, admin_url() ), 'wc_pb_force_db_update_nonce', '_wc_pb_admin_nonce' ) );
			$fallback_link   = '<a href="' . $fallback_url . '">' . __( 'run it manually', 'lafka-plugin' ) . '</a>';
			$fallback_prompt = sprintf( __( ' The process seems to be taking a little longer than usual, so let\'s try to %s.', 'lafka-plugin' ), $fallback_link );
		}

		return $fallback_prompt;
	}

	/**
	 * Returns a "failed update" notice component.
	 *
	 * @since  5.5.0
	 *
	 * @return string
	 */
	private static function get_failed_update_prompt() {

		$support_prompt = __( ' If this message persists, please restore your database from a backup.', 'lafka-plugin' );

		return $support_prompt;
	}

	/**
	 * Dismisses a notice. Dismissible maintenance notices cannot be dismissed forever.
	 *
	 * @since  5.8.0
	 *
	 * @param  string  $notice
	 */
	public static function dismiss_notice( $notice ) {
			return self::remove_dismissible_notice( $notice );
	}

	/*
	|--------------------------------------------------------------------------
	| Notes for the WC Admin Inbox.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add note.
	 *
	 * @since  6.3.0
	 *
	 * @param  array|string  $args
	 */
	public static function add_note( $args ) {

		if ( ! class_exists( 'WC_LafkaCombos_Core_Compatibility' ) ) {
			require_once  WC_LafkaCombos_ABSPATH . 'includes/compatibility/core/class-lafka-combos-core-compatibility.php' ;
		}

		if ( ! WC_LafkaCombos_Core_Compatibility::is_wc_admin_active() ) {
			return;
		}

		$note_class = false;

		if ( class_exists( 'Automattic\WooCommerce\Admin\Notes\Note' ) ) {
			$note_class = 'Automattic\WooCommerce\Admin\Notes\Note';
		} elseif ( class_exists( 'Automattic\WooCommerce\Admin\Notes\WC_Admin_Note' ) ) {
			$note_class = 'Automattic\WooCommerce\Admin\Notes\WC_Admin_Note';
		} else {
			return;
		}

		if ( ! is_array( $args ) ) {
			$args = self::get_note_args( $args );
		}

		if ( ! is_array( $args ) ) {
			return;
		}

		$default_args = array(
			'name'         => '',
			'title'        => '',
			'content'      => '',
			'type'         => $note_class::E_WC_ADMIN_NOTE_INFORMATIONAL,
			'source'       => '',
			'icon'         => '',
			'check_plugin' => '',
			'actions'      => array()
		);

		$args = wp_parse_args( $args, $default_args );

		if ( empty( $args[ 'name' ] ) || empty( $args[ 'title' ] ) || empty( $args[ 'content' ] ) || empty( $args[ 'type' ] ) || empty( $args[ 'icon' ] ) ) {
			return false;
		}

		// First, see if we've already created this note so we don't do it again.
		$data_store = WC_Data_Store::load( 'admin-note' );
		$note_ids   = $data_store->get_notes_with_name( $args[ 'name' ] );
		if ( ! empty( $note_ids ) ) {
			return;
		}

		// Otherwise, add the note.
		$note = new $note_class();

		$note->set_name( $args[ 'name' ] );
		$note->set_title( $args[ 'title' ] );
		$note->set_content( $args[ 'content' ] );
		$note->set_type( $args[ 'type' ] );

		if ( ! method_exists( $note, 'set_image' ) ) {
			$note->set_icon( $args[ 'icon' ] );
		}

		if ( $args[ 'source' ] ) {
			$note->set_source( $args[ 'source' ] );
		}

		if ( is_array( $args[ 'actions' ] ) ) {
			foreach ( $args[ 'actions' ] as $action ) {
				if ( empty( $action[ 'name' ] ) || empty( $action[ 'label' ] ) ) {
					continue;
				}
				$note->add_action( $action[ 'name' ], $action[ 'label' ], empty( $action[ 'url' ] ) ? false : $action[ 'url' ], empty( $action[ 'status' ] ) ? $note_class::E_WC_ADMIN_NOTE_UNACTIONED : $action[ 'status' ], empty( $action[ 'primary' ] ) ? false : $action[ 'primary' ] );
			}
		}

		// Check if plugin installed or activated.
		if ( ! empty( $args[ 'check_plugin' ] ) ) {
			if ( WC_LafkaCombos_Notices::is_feature_plugin_installed( $args[ 'name' ] ) ) {
				$note->set_status( $note_class::E_WC_ADMIN_NOTE_ACTIONED );
			}
		}

		$note->save();
	}

	/**
	 * Get note data.
	 *
	 * @since 6.3.0
	 *
	 * @param  string  $name
	 * @return array
	 */
	public static function get_note_args( $name ) {

		$note_class = false;

		if ( class_exists( 'Automattic\WooCommerce\Admin\Notes\Note' ) ) {
			$note_class = 'Automattic\WooCommerce\Admin\Notes\Note';
		} elseif ( class_exists( 'Automattic\WooCommerce\Admin\Notes\WC_Admin_Note' ) ) {
			$note_class = 'Automattic\WooCommerce\Admin\Notes\WC_Admin_Note';
		} else {
			return;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Act upon clicking on a 'dismiss notice' link.
	 *
	 * @deprecated  3.14.0
	 */
	public static function dismiss_notice_handler() {
		if ( isset( $_GET[ 'dismiss_wc_pb_notice' ] ) && isset( $_GET[ '_wc_pb_admin_nonce' ] ) ) {
			if ( ! wp_verify_nonce( wc_clean( $_GET[ '_wc_pb_admin_nonce' ] ), 'wc_pb_dismiss_notice_nonce' ) ) {
				wp_die( __( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce' ) );
			}

			$notice = sanitize_text_field( $_GET[ 'dismiss_wc_pb_notice' ] );

			self::dismiss_notice( $notice );
		}
	}
}

WC_LafkaCombos_Admin_Notices::init();
