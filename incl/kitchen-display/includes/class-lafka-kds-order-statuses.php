<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Order_Statuses {

	public function __construct() {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_statuses' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'valid_for_payment' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_head', array( $this, 'admin_order_status_colors' ) );
	}

	/**
	 * Register custom post statuses.
	 */
	public function register_statuses() {
		register_post_status( 'wc-accepted', array(
			'label'                     => _x( 'Accepted', 'Order status', 'lafka-plugin' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Accepted <span class="count">(%s)</span>', 'Accepted <span class="count">(%s)</span>', 'lafka-plugin' ),
		) );

		register_post_status( 'wc-preparing', array(
			'label'                     => _x( 'Preparing', 'Order status', 'lafka-plugin' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Preparing <span class="count">(%s)</span>', 'Preparing <span class="count">(%s)</span>', 'lafka-plugin' ),
		) );

		register_post_status( 'wc-ready', array(
			'label'                     => _x( 'Ready', 'Order status', 'lafka-plugin' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Ready <span class="count">(%s)</span>', 'Ready <span class="count">(%s)</span>', 'lafka-plugin' ),
		) );

		register_post_status( 'wc-rejected', array(
			'label'                     => _x( 'Rejected', 'Order status', 'lafka-plugin' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Rejected <span class="count">(%s)</span>', 'Rejected <span class="count">(%s)</span>', 'lafka-plugin' ),
		) );
	}

	/**
	 * Add statuses to WC dropdown, inserted after Processing.
	 */
	public function add_statuses( $statuses ) {
		$new = array();
		foreach ( $statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new['wc-accepted']  = _x( 'Accepted', 'Order status', 'lafka-plugin' );
				$new['wc-preparing'] = _x( 'Preparing', 'Order status', 'lafka-plugin' );
				$new['wc-ready']     = _x( 'Ready', 'Order status', 'lafka-plugin' );
				$new['wc-rejected']  = _x( 'Rejected', 'Order status', 'lafka-plugin' );
			}
		}

		return $new;
	}

	/**
	 * Allow these statuses for payment_complete.
	 */
	public function valid_for_payment( $statuses ) {
		$statuses[] = 'accepted';
		$statuses[] = 'preparing';
		$statuses[] = 'ready';
		$statuses[] = 'rejected';

		return $statuses;
	}

	/**
	 * Add bulk actions for custom statuses.
	 */
	public function bulk_actions( $actions ) {
		$actions['mark_accepted']  = __( 'Change status to accepted', 'lafka-plugin' );
		$actions['mark_preparing'] = __( 'Change status to preparing', 'lafka-plugin' );
		$actions['mark_ready']     = __( 'Change status to ready', 'lafka-plugin' );
		$actions['mark_rejected']  = __( 'Change status to rejected', 'lafka-plugin' );

		return $actions;
	}

	/**
	 * Add colored dots for custom statuses on admin order list.
	 */
	public function admin_order_status_colors() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$valid_screens = array( 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( ! in_array( $screen->id, $valid_screens, true ) ) {
			return;
		}
		?>
		<style>
			.order-status.status-accepted { background: #c8d7e1; color: #2e4453; }
			.order-status.status-preparing { background: #f8dda7; color: #94660c; }
			.order-status.status-ready { background: #c6e1c6; color: #5b841b; }
			.order-status.status-rejected { background: #eba3a3; color: #761919; }
		</style>
		<?php
	}

	/**
	 * Handle bulk status change actions.
	 */
	public function handle_bulk_actions( $redirect_to, $action, $order_ids ) {
		$status_map = array(
			'mark_accepted'  => 'accepted',
			'mark_preparing' => 'preparing',
			'mark_ready'     => 'ready',
			'mark_rejected'  => 'rejected',
		);

		if ( ! isset( $status_map[ $action ] ) ) {
			return $redirect_to;
		}

		$new_status = $status_map[ $action ];
		$changed    = 0;

		// Allowed transitions mirror the KDS workflow (forward, undo, and reject)
		$allowed_transitions = array(
			'processing' => array( 'accepted', 'rejected' ),
			'on-hold'    => array( 'accepted' ),
			'accepted'   => array( 'preparing', 'rejected', 'processing' ),
			'preparing'  => array( 'ready', 'accepted' ),
			'ready'      => array( 'completed', 'preparing' ),
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			// Validate transition: only allow permitted statuses in the workflow
			$current = $order->get_status();
			if ( ! isset( $allowed_transitions[ $current ] ) || ! in_array( $new_status, $allowed_transitions[ $current ], true ) ) {
				continue;
			}

			if ( 'accepted' === $new_status ) {
				$order->update_meta_data( '_lafka_kds_accepted_at', time() );
			}
			$order->set_status( $new_status );
			$order->save();
			$changed++;
		}

		return add_query_arg( array(
			'bulk_action' => $action,
			'changed'     => $changed,
		), $redirect_to );
	}
}
