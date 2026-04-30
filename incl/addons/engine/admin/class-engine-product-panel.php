<?php
/**
 * Lafka_Engine_Product_Panel — addon-group editor inside the WC product
 * editor's tab.
 *
 * Hooks:
 *   woocommerce_product_write_panel_tabs → register the tab
 *   woocommerce_product_data_panels      → render the panel
 *   woocommerce_process_product_meta     → save handler (priority 1)
 *
 * Uses the same Lafka_Engine_Editor parse + expand + repository pipeline
 * as the global addon page, so per-product addons land in the same canonical
 * shape (engine v2 schema) and downstream cart/display read both
 * uniformly.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.2
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Product_Panel {

	/** @var Lafka_Engine_Editor */
	private $editor;

	public function __construct( Lafka_Engine_Editor $editor ) {
		$this->editor = $editor;

		add_action( 'woocommerce_product_write_panel_tabs', array( $this, 'render_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 1 );
	}

	/**
	 * Tab nav item.
	 */
	public function render_tab(): void {
		?>
		<li class="addons_tab lafka_engine_addons">
			<a href="#lafka_engine_addons_panel">
				<span><?php esc_html_e( 'Lafka Add-ons', 'lafka-plugin' ); ?></span>
			</a>
		</li>
		<?php
	}

	/**
	 * Tab panel body.
	 */
	public function render_panel(): void {
		global $post;
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$post_id            = (int) $post->ID;
		$groups             = Lafka_Addons_Engine::instance()->repository()->get_groups( $post_id );
		$exclude_global     = (string) get_post_meta( $post_id, '_product_addons_exclude_global', true ) === '1';
		$product_attributes = function_exists( 'wc_get_attribute_taxonomies' )
			? wc_get_attribute_taxonomies()
			: array();

		// Always provide at least one empty group so the form has something to render.
		if ( empty( $groups ) ) {
			$groups = array( Lafka_Addon_Group::from_array( array() ) );
		}

		require __DIR__ . '/views/product-panel.php';
	}

	/**
	 * Save the per-product addon groups when the WC product editor saves.
	 *
	 * Mirrors the global editor's save pipeline:
	 *   $_POST → parse_groups → expand_groups → repository->save_groups
	 *
	 * Plus the global-exclude flag (per-product opt-out from global addon
	 * groups assigned to the product's category).
	 */
	public function save( int $post_id ): void {
		// Defense-in-depth — see also legacy guards we ported.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// `lafka_addon_groups` is the panel's canonical marker. If absent,
		// the addon panel wasn't part of this save — bail rather than wipe.
		if ( ! isset( $_POST['lafka_addon_groups'] ) ) {
			return;
		}

		$post_data = wp_unslash( $_POST );

		$groups = $this->editor->parse_groups( $post_data );
		$groups = $this->editor->expand_groups( $groups );

		Lafka_Addons_Engine::instance()->repository()->save_groups( $post_id, $groups );

		$exclude_global = ! empty( $post_data['lafka_addons_exclude_global'] ) ? 1 : 0;
		update_post_meta( $post_id, '_product_addons_exclude_global', $exclude_global );
	}
}
