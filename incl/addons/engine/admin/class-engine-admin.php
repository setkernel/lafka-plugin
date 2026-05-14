<?php
/**
 * Lafka_Engine_Admin — top-level admin controller for the v2 addon engine.
 *
 * Registers the "Lafka Add-ons" submenu under the Products menu, enqueues
 * editor assets when the screen is in scope, dispatches list-vs-edit mode,
 * and handles the trash action.
 *
 * Phase 2: this class replaces the legacy Lafka_Product_Addon_Admin's
 * global-admin surface. The legacy class is no longer instantiated by the
 * loader (see incl/addons/lafka-product-addons.php). Per-product addon
 * panel integration on the WC product editor lands in Phase 3.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

// $_GET reads in this admin controller are for routing display state
// (list vs edit mode, edit_id, action, paged) — no state mutation.
// Write actions (save/trash) go through check_admin_referer() in their handlers.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

class Lafka_Engine_Admin {

	const PAGE_SLUG  = 'lafka_addons';
	const NONCE_NAME = 'lafka_addons_save';
	const SCREEN_ID  = 'product_page_lafka_addons';

	private Lafka_Engine_Editor $editor;
	private Lafka_Engine_Ajax $ajax;
	private Lafka_Engine_Product_Panel $product_panel;

	public function __construct() {
		$this->editor        = new Lafka_Engine_Editor();
		$this->ajax          = new Lafka_Engine_Ajax();
		$this->product_panel = new Lafka_Engine_Product_Panel( $this->editor );

		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'woocommerce_screen_ids', array( $this, 'register_screen_id' ) );
	}

	/**
	 * Add the submenu page under Products.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			esc_html__( 'Lafka Add-ons', 'lafka-plugin' ),
			esc_html__( 'Lafka Add-ons', 'lafka-plugin' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_screen_id( array $ids ): array {
		$ids[] = self::SCREEN_ID;
		return $ids;
	}

	/**
	 * Top-level page render — dispatches based on $_GET state.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage addons.', 'lafka-plugin' ) );
		}

		// Add / edit mode.
		if ( ! empty( $_GET['add'] ) || ! empty( $_GET['edit'] ) ) {
			$this->editor->dispatch();
			return;
		}

		// Trash action — performed before list render.
		if ( ! empty( $_GET['delete'] ) ) {
			$this->handle_trash( absint( $_GET['delete'] ) );
		}

		$this->render_list();
	}

	private function handle_trash( int $id ): void {
		if ( ! $id || 'lafka_glb_addon' !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Invalid addon ID.', 'lafka-plugin' ) );
		}
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'delete_addon_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'lafka-plugin' ) );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			wp_die( esc_html__( 'You do not have permission to delete this addon.', 'lafka-plugin' ) );
		}
		wp_trash_post( $id );
		echo '<div class="updated"><p>' . esc_html__( 'Add-on moved to trash.', 'lafka-plugin' ) . '</p></div>';
	}

	private function render_list(): void {
		require_once __DIR__ . '/class-list-table.php';
		$table = new Lafka_Engine_Addons_List_Table();
		$table->prepare_items();
		require __DIR__ . '/views/global-list.php';
	}

	/**
	 * Enqueue editor JS + CSS only on the addon screens.
	 */
	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		// Two screens use the editor: the global addons page AND the WC
		// product editor (where the per-product addon panel lives).
		$is_addons_page = self::SCREEN_ID === $screen->id;
		$is_product_edit = ( 'product' === $screen->id || 'product' === ( $screen->post_type ?? '' ) );
		if ( ! $is_addons_page && ! $is_product_edit ) {
			return;
		}

		$dir = trailingslashit( LAFKA_ADDONS_ENGINE_PATH ) . 'admin/assets/';
		$url = trailingslashit( plugins_url( 'admin/assets/', LAFKA_ADDONS_ENGINE_PATH . '/.' ) );

		wp_enqueue_style(
			'lafka-addons-engine-admin',
			$url . 'admin.css',
			array(),
			file_exists( $dir . 'admin.css' ) ? (string) filemtime( $dir . 'admin.css' ) : '8.13.0'
		);

		wp_enqueue_script(
			'lafka-addons-engine-admin',
			$url . 'admin.js',
			array( 'jquery' ),
			file_exists( $dir . 'admin.js' ) ? (string) filemtime( $dir . 'admin.js' ) : '8.13.0',
			true
		);

		wp_localize_script(
			'lafka-addons-engine-admin',
			'lafkaAddonsEngineAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'syncNonce'  => wp_create_nonce( Lafka_Engine_Ajax::SYNC_NONCE ),
				'i18n'       => array(
					'syncFailed'        => __( 'Could not sync from attribute. Try again.', 'lafka-plugin' ),
					'confirmRemoveOpt'  => __( 'Remove this option?', 'lafka-plugin' ),
					'confirmRemoveGrp'  => __( 'Remove this addon group?', 'lafka-plugin' ),
				),
			)
		);
	}
}
