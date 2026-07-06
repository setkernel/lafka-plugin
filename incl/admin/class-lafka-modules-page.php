<?php
/**
 * Lafka_Modules_Page — the "Lafka → Modules" feature dashboard (NX1-01).
 *
 * One screen where the operator can see and flip every gated Lafka module they
 * own — the five Lafka_Options flags plus the conversion modules that used to
 * self-gate in scattered Customizer panels. Every card reads its state, label,
 * description, configuration status, settings link and docs link from the
 * typed Lafka_Module_Registry, so this page never hardcodes a module list and
 * new modules appear automatically once registered.
 *
 * Toggling a module writes the SAME option the current gate code already reads
 * (via Lafka_Module::set_enabled()), so flipping here is behaviourally
 * identical to flipping via WP-CLI — no new storage, no engine changes.
 *
 * The toggle is a plain nonce-verified POST to admin-post.php (no JS
 * framework); the page itself is plain PHP/HTML with a small inline admin
 * stylesheet.
 *
 * @package Lafka
 * @since   9.36.0
 */

// $_GET reads below are display-state only (which module was just updated, for
// the success notice). The state-changing path is the admin-post toggle
// handler, which verifies its own nonce via check_admin_referer().
// phpcs:disable WordPress.Security.NonceVerification.Recommended

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Modules_Page' ) ) {

	final class Lafka_Modules_Page {

		const MENU_SLUG    = 'lafka-modules';
		const CAPABILITY   = 'manage_woocommerce';
		const NONCE_ACTION = 'lafka_module_toggle';
		const TOGGLE_ACTION = 'lafka_module_toggle';

		// NX1-06: opt-in "Remove all data on uninstall" toggle. The option name
		// MUST match Lafka_Uninstall::DATA_TOGGLE_OPTION (uninstall.php reads it).
		const DATA_REMOVAL_ACTION = 'lafka_data_removal_toggle';
		const DATA_REMOVAL_NONCE  = 'lafka_data_removal_toggle';
		const DATA_REMOVAL_OPTION = 'lafka_delete_data_on_uninstall';

		/** @var Lafka_Modules_Page|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
			add_action( 'admin_post_' . self::DATA_REMOVAL_ACTION, array( $this, 'handle_data_removal_toggle' ) );
		}

		/**
		 * Register the top-level "Lafka" menu + its first submenu, "Modules".
		 */
		public function register_menu() {
			add_menu_page(
				esc_html__( 'Lafka', 'lafka-plugin' ),
				esc_html__( 'Lafka', 'lafka-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render_page' ),
				'dashicons-store',
				56
			);
			add_submenu_page(
				self::MENU_SLUG,
				esc_html__( 'Feature Modules', 'lafka-plugin' ),
				esc_html__( 'Modules', 'lafka-plugin' ),
				self::CAPABILITY,
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * admin-post handler: flip a single module's enabled state.
		 */
		public function handle_toggle() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to change Lafka modules.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::NONCE_ACTION );

			$module_id = isset( $_POST['lafka_module'] )
				? sanitize_key( wp_unslash( $_POST['lafka_module'] ) )
				: '';
			$enabled   = isset( $_POST['lafka_module_enabled'] )
				&& '1' === sanitize_text_field( wp_unslash( $_POST['lafka_module_enabled'] ) );

			$module = Lafka_Module_Registry::get( $module_id );
			$result = 'invalid';
			if ( $module instanceof Lafka_Module && ! $module->is_read_only() ) {
				$module->set_enabled( $enabled );
				$result = $enabled ? 'enabled' : 'disabled';
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => self::MENU_SLUG,
						'lafka_module'  => $module_id,
						'lafka_updated' => $result,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * admin-post handler: flip the opt-in "Remove all data on uninstall"
		 * option (NX1-06). Unchecked box → option set to '0' (default behaviour:
		 * keep data). Checked → '1' (uninstall.php then runs the full wipe).
		 */
		public function handle_data_removal_toggle() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to change Lafka data-removal settings.', 'lafka-plugin' ), 403 );
			}
			check_admin_referer( self::DATA_REMOVAL_NONCE );

			$enabled = isset( $_POST['lafka_delete_data_on_uninstall'] )
				&& '1' === sanitize_text_field( wp_unslash( $_POST['lafka_delete_data_on_uninstall'] ) );

			update_option( self::DATA_REMOVAL_OPTION, $enabled ? '1' : '0' );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'               => self::MENU_SLUG,
						'lafka_data_removal' => $enabled ? 'on' : 'off',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Render the Modules dashboard.
		 */
		public function render_page() {
			if ( ! current_user_can( self::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to view this page.', 'lafka-plugin' ), 403 );
			}

			$modules = Lafka_Module_Registry::all();

			// Group by category, preserving registration order within a group.
			$grouped = array();
			foreach ( $modules as $module ) {
				$grouped[ $module->get_category() ][] = $module;
			}

			echo '<div class="wrap lafka-modules">';
			echo '<h1>' . esc_html__( 'Lafka Modules', 'lafka-plugin' ) . '</h1>';
			echo '<p class="description">' . esc_html__( 'Turn Lafka features on or off, and see which ones still need configuring. Toggling a module here is the same as flipping its option anywhere else — enabling a module loads it on the next page request.', 'lafka-plugin' ) . '</p>';

			$this->render_notice();
			$this->render_styles();

			foreach ( $grouped as $category => $group ) {
				echo '<h2 class="lafka-modules__category">' . esc_html( Lafka_Module_Registry::category_label( $category ) ) . '</h2>';
				echo '<div class="lafka-modules__grid">';
				foreach ( $group as $module ) {
					$this->render_module_card( $module );
				}
				echo '</div>';
			}

			$this->render_data_removal_section();

			echo '</div>';
		}

		/**
		 * The opt-in "Remove all data on uninstall" control (NX1-06), rendered
		 * as a clearly-warned danger zone below the module grid.
		 */
		private function render_data_removal_section() {
			$stored  = get_option( self::DATA_REMOVAL_OPTION, '0' );
			$enabled = '1' === ( is_scalar( $stored ) ? (string) $stored : '0' );

			$this->render_data_removal_notice();

			echo '<h2 class="lafka-modules__category">' . esc_html__( 'Data removal', 'lafka-plugin' ) . '</h2>';
			echo '<div class="lafka-danger-zone">';
			echo '<h3 class="lafka-danger-zone__title">' . esc_html__( 'Remove all Lafka data on uninstall', 'lafka-plugin' ) . '</h3>';
			echo '<p class="lafka-danger-zone__desc">' . esc_html__( 'By default, deleting the plugin keeps your data so a re-install resumes where you left off. Turn this on to make uninstalling the plugin erase every trace of Lafka.', 'lafka-plugin' ) . '</p>';
			echo '<p class="lafka-danger-zone__warning">' . esc_html__( 'Warning: with this enabled, uninstalling permanently deletes your menu products\' Lafka details (dietary tags, allergens, nutrition), delivery branches and zones, order hours, add-on groups, and all Lafka settings and transients. Your WooCommerce orders and their history are always kept.', 'lafka-plugin' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::DATA_REMOVAL_ACTION ) . '">';
			wp_nonce_field( self::DATA_REMOVAL_NONCE );
			echo '<label class="lafka-danger-zone__toggle"><input type="checkbox" name="lafka_delete_data_on_uninstall" value="1"';
			if ( $enabled ) {
				echo ' checked="checked"';
			}
			echo '> ' . esc_html__( 'Erase all Lafka data when the plugin is uninstalled', 'lafka-plugin' ) . '</label> ';
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Save', 'lafka-plugin' ) . '</button>';
			echo '</form>';
			echo '</div>';
		}

		/**
		 * Success notice after saving the data-removal toggle.
		 */
		private function render_data_removal_notice() {
			$state = isset( $_GET['lafka_data_removal'] ) ? sanitize_text_field( wp_unslash( $_GET['lafka_data_removal'] ) ) : '';
			if ( 'on' !== $state && 'off' !== $state ) {
				return;
			}
			$message = 'on' === $state
				? esc_html__( 'Lafka will remove all its data when the plugin is uninstalled.', 'lafka-plugin' )
				: esc_html__( 'Lafka will keep your data when the plugin is uninstalled.', 'lafka-plugin' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * Success notice after a toggle round-trip.
		 */
		private function render_notice() {
			$updated = isset( $_GET['lafka_updated'] ) ? sanitize_text_field( wp_unslash( $_GET['lafka_updated'] ) ) : '';
			if ( '' === $updated || 'invalid' === $updated ) {
				return;
			}
			$module_id = isset( $_GET['lafka_module'] ) ? sanitize_key( wp_unslash( $_GET['lafka_module'] ) ) : '';
			$module    = Lafka_Module_Registry::get( $module_id );
			$name      = $module instanceof Lafka_Module ? $module->get_label() : $module_id;

			$message = 'enabled' === $updated
				/* translators: %s: module name. */
				? sprintf( esc_html__( '%s enabled.', 'lafka-plugin' ), $name )
				/* translators: %s: module name. */
				: sprintf( esc_html__( '%s disabled.', 'lafka-plugin' ), $name );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/**
		 * One card per module: name, description, status badge, toggle (or a
		 * read-only note), and settings + docs links.
		 */
		private function render_module_card( Lafka_Module $module ) {
			$id           = $module->get_id();
			$enabled      = $module->is_enabled();
			$badge        = $this->status_badge( $module );
			$settings_url = $module->get_settings_url();
			$docs_url     = Lafka_Module_Registry::docs_url( $module );

			echo '<div class="lafka-module-card" data-lafka-module="' . esc_attr( $id ) . '">';

			echo '<div class="lafka-module-card__head">';
			echo '<h3 class="lafka-module-card__title">' . esc_html( $module->get_label() ) . '</h3>';
			echo '<span class="lafka-badge lafka-badge--' . esc_attr( $badge['tone'] ) . '">' . esc_html( $badge['label'] ) . '</span>';
			echo '</div>';

			echo '<p class="lafka-module-card__desc">' . esc_html( $module->get_description() ) . '</p>';

			echo '<div class="lafka-module-card__actions">';

			if ( $module->is_read_only() ) {
				echo '<span class="lafka-module-card__readonly">' . esc_html__( 'Managed automatically', 'lafka-plugin' ) . '</span>';
			} else {
				$this->render_toggle_form( $module, $enabled );
			}

			if ( '' !== $settings_url ) {
				echo '<a class="button button-secondary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'lafka-plugin' ) . '</a>';
			}
			if ( '' !== $docs_url ) {
				echo '<a class="lafka-module-card__docs" href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'lafka-plugin' ) . '</a>';
			}

			echo '</div>';
			echo '</div>';
		}

		/**
		 * The plain nonce-verified POST toggle for a flippable module.
		 */
		private function render_toggle_form( Lafka_Module $module, $enabled ) {
			$target      = $enabled ? '0' : '1';
			$button_text = $enabled ? esc_html__( 'Disable', 'lafka-plugin' ) : esc_html__( 'Enable', 'lafka-plugin' );
			$button_class = $enabled ? 'button button-secondary' : 'button button-primary';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::TOGGLE_ACTION ) . '">';
			echo '<input type="hidden" name="lafka_module" value="' . esc_attr( $module->get_id() ) . '">';
			echo '<input type="hidden" name="lafka_module_enabled" value="' . esc_attr( $target ) . '">';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<button type="submit" class="' . esc_attr( $button_class ) . '">' . esc_html( $button_text ) . '</button>';
			echo '</form>';
		}

		/**
		 * Compute the status badge (label + tone) for a module.
		 *
		 * @return array{label:string,tone:string}
		 */
		private function status_badge( Lafka_Module $module ) {
			if ( $module->is_read_only() ) {
				return $module->is_enabled()
					? array(
						'label' => esc_html__( 'Active', 'lafka-plugin' ),
						'tone'  => 'active',
					)
					: array(
						'label' => esc_html__( 'Not configured', 'lafka-plugin' ),
						'tone'  => 'inactive',
					);
			}

			if ( ! $module->is_enabled() ) {
				return array(
					'label' => esc_html__( 'Inactive', 'lafka-plugin' ),
					'tone'  => 'inactive',
				);
			}

			if ( ! $module->is_configured() ) {
				return array(
					'label' => esc_html__( 'Needs configuration', 'lafka-plugin' ),
					'tone'  => 'warning',
				);
			}

			return array(
				'label' => esc_html__( 'Active', 'lafka-plugin' ),
				'tone'  => 'active',
			);
		}

		/**
		 * Minimal inline admin CSS for the cards (no external asset — this page
		 * is admin-only and rarely visited).
		 */
		private function render_styles() {
			echo '<style>
				.lafka-modules__category{margin-top:2em;font-size:1.1em;}
				.lafka-modules__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;}
				.lafka-module-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;display:flex;flex-direction:column;}
				.lafka-module-card__head{display:flex;align-items:center;justify-content:space-between;gap:8px;}
				.lafka-module-card__title{margin:0;font-size:1.05em;}
				.lafka-module-card__desc{color:#50575e;margin:8px 0 14px;flex:1 1 auto;}
				.lafka-module-card__actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
				.lafka-module-card__readonly{color:#646970;font-style:italic;}
				.lafka-module-card__docs{align-self:center;}
				.lafka-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.8;}
				.lafka-badge--active{background:#edfaef;color:#00694e;}
				.lafka-badge--warning{background:#fcf3e6;color:#8a5700;}
				.lafka-badge--inactive{background:#f0f0f1;color:#646970;}
				.lafka-danger-zone{background:#fff;border:1px solid #d63638;border-left-width:4px;border-radius:8px;padding:16px 18px;max-width:720px;}
				.lafka-danger-zone__title{margin:0 0 6px;font-size:1.05em;color:#8a2424;}
				.lafka-danger-zone__desc{color:#50575e;margin:0 0 8px;}
				.lafka-danger-zone__warning{color:#8a2424;font-weight:600;margin:0 0 14px;}
				.lafka-danger-zone__toggle{display:inline-block;margin-right:8px;}
			</style>';
		}
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		Lafka_Modules_Page::instance();
	}
}
