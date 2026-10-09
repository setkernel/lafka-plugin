<?php
/**
 * First-order discount — standalone, always-loaded.
 *
 * An automatic percentage discount on a customer's FIRST order, to convert
 * first-time visitors (esp. those arriving from the delivery apps) into direct
 * customers. Like free-delivery, it is its own independently-toggled feature
 * (activates purely when the percent is > 0), NOT behind the BOGO module gate.
 *
 * Abuse-resistance: eligibility is intentionally limited to LOGGED-IN customers
 * with no prior order — a guest has no history, so a guest-eligible perk
 * could be claimed forever. A person is recognised by account, billing email
 * and billing phone (see lafka_first_order_identity_order_ids()); one order
 * holds the discount (see "One holder per person" below), and an order paid
 * with it after another order of the same person was is flagged for staff.
 * Inherent limit of any first-order offer: someone using an all-new account,
 * email AND phone is a new customer to the shop and gets it again — keep the
 * percentage modest, or use a WooCommerce coupon limited to one use per email.
 * The eligibility test is filterable (`lafka_is_first_order_customer`).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_first_order_discount_percent' ) ) {
	/**
	 * SSOT first-order discount percent (0 = off). Source order:
	 * filter → option → 0.
	 *
	 * @return float 0–100.
	 */
	function lafka_first_order_discount_percent(): float {
		$percent = (float) get_option( 'lafka_first_order_discount_percent', 0 );
		$percent = (float) apply_filters( 'lafka_first_order_discount_percent', $percent );
		return min( 100.0, max( 0.0, $percent ) );
	}
}

if ( ! function_exists( 'lafka_first_order_counted_statuses' ) ) {
	/**
	 * The order statuses that make a customer no longer "first order": every
	 * status except failed, cancelled and the block checkout's draft. A pending
	 * order counts (it can still be paid, e.g. through a payment link), so a
	 * customer cannot open several discounted orders and pay them all.
	 *
	 * @since 10.4.0
	 * @return string[]
	 */
	function lafka_first_order_counted_statuses(): array {
		$all = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		return array_values( array_diff( $all, array( 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ) ) );
	}
}

if ( ! function_exists( 'lafka_first_order_paid_statuses' ) ) {
	/**
	 * Statuses of an order that went through: WooCommerce's paid statuses
	 * (processing, completed) and refunded.
	 *
	 * @since 10.4.0
	 * @return string[] wc- prefixed.
	 */
	function lafka_first_order_paid_statuses(): array {
		$paid = function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$paid = array_unique( array_merge( $paid, array( 'refunded' ) ) );
		return array_values(
			array_map(
				static function ( $status ) {
					return 0 === strpos( (string) $status, 'wc-' ) ? (string) $status : 'wc-' . $status;
				},
				$paid
			)
		);
	}
}

if ( ! function_exists( 'lafka_first_order_retry_ids' ) ) {
	/**
	 * The order this checkout is (re)paying, which never counts against
	 * itself: the classic checkout's `order_awaiting_payment` and the block
	 * checkout's Store API draft order.
	 *
	 * @since 10.4.0
	 * @return int[]
	 */
	function lafka_first_order_retry_ids(): array {
		$session = function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;
		if ( null === $session ) {
			return array();
		}
		return array_values( array_filter( array( absint( $session->get( 'order_awaiting_payment' ) ), absint( $session->get( 'store_api_draft_order' ) ) ) ) );
	}
}

if ( ! function_exists( 'lafka_first_order_identity_order_ids' ) ) {
	/**
	 * Orders that make this person not new, whichever account (or none)
	 * placed them. The account's own orders count in every counted status
	 * (lafka_first_order_counted_statuses()). Orders found by billing email
	 * (case-insensitive) or billing phone (compared in E.164,
	 * lafka_phone_to_e164()) count only once they went through
	 * (lafka_first_order_paid_statuses()), so a new account or a guest order
	 * cannot claim the discount again, and nobody can take a stranger's
	 * discount away by typing their email or phone on an unpaid order.
	 * Read-only: used to decide this order's eligibility, never to change
	 * another person's order.
	 *
	 * @since 10.4.0
	 * @param int      $user_id Account id (0 for none).
	 * @param string[] $emails  Billing / account emails.
	 * @param string   $phone   Billing phone as typed.
	 * @param int[]    $exclude Order ids to leave out.
	 * @return int[]
	 */
	function lafka_first_order_identity_order_ids( int $user_id, array $emails, string $phone, array $exclude = array() ): array {
		$exclude = array_values( array_filter( array_map( 'absint', $exclude ) ) );
		$base    = array(
			'exclude' => $exclude,
			'limit'   => 50,
			'return'  => 'ids',
			'type'    => 'shop_order',
		);
		$paid    = lafka_first_order_paid_statuses();
		$ids     = array();
		if ( $user_id > 0 ) {
			$ids = array_merge(
				$ids,
				wc_get_orders(
					$base + array(
						'customer_id' => $user_id,
						'status'      => lafka_first_order_counted_statuses(),
					)
				)
			);
		}
		foreach ( array_unique( array_filter( array_map( 'trim', $emails ) ) ) as $email ) {
			if ( is_email( $email ) ) {
				$ids = array_merge(
					$ids,
					wc_get_orders(
						$base + array(
							'billing_email' => $email,
							'status'        => $paid,
						)
					)
				);
			}
		}
		$e164 = function_exists( 'lafka_phone_to_e164' ) ? lafka_phone_to_e164( $phone ) : '';
		if ( '' !== $e164 ) {
			$ids = array_merge( $ids, lafka_first_order_orders_by_phone( $e164, $paid, $exclude ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}

if ( ! function_exists( 'lafka_first_order_orders_by_phone' ) ) {
	/**
	 * Orders whose billing phone normalises to the same E.164 number. The
	 * stored phones are formatted as typed, so candidates are found by their
	 * last four digits and compared after normalising.
	 *
	 * @since 10.4.0
	 * @param string   $e164     Normalised phone.
	 * @param string[] $statuses Statuses (wc- prefixed).
	 * @param int[]    $exclude  Order ids to leave out.
	 * @return int[]
	 */
	function lafka_first_order_orders_by_phone( string $e164, array $statuses, array $exclude ): array {
		global $wpdb;
		if ( array() === $statuses ) {
			return array();
		}
		// The last seven digits in order with anything between them ("555-0123",
		// "5550123"): selective enough that the cap below is never reached.
		$like = '%' . implode( '%', str_split( substr( preg_replace( '/\D+/', '', $e164 ), -7 ) ) ) . '%';
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		if ( $hpos ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT o.id AS id, a.phone AS phone FROM %i o INNER JOIN %i a ON a.order_id = o.id AND a.address_type = 'billing' WHERE o.type = 'shop_order' AND a.phone LIKE %s AND o.status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') LIMIT 200',
					array_merge( array( $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'wc_order_addresses', $like ), $statuses )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS id, m.meta_value AS phone FROM %i p INNER JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_billing_phone' WHERE p.post_type = 'shop_order' AND m.meta_value LIKE %s AND p.post_status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') LIMIT 200',
					array_merge( array( $wpdb->posts, $wpdb->postmeta, $like ), $statuses )
				),
				ARRAY_A
			);
		}
		$ids = array();
		foreach ( (array) $rows as $row ) {
			if ( ! in_array( (int) $row['id'], $exclude, true ) && lafka_phone_to_e164( (string) $row['phone'] ) === $e164 ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}
}

if ( ! function_exists( 'lafka_first_order_typed_contact' ) ) {
	add_action( 'woocommerce_checkout_update_order_review', 'lafka_first_order_typed_contact' );
	/**
	 * The billing email and phone typed on the classic checkout. WooCommerce's
	 * order-review refresh only stores the address on the customer, so the
	 * refresh's form data is read here (for this request only) to price the
	 * first-order discount for the person actually checking out.
	 *
	 * @since 10.4.0
	 * @param mixed $post_data Serialised checkout form, or null to read.
	 * @return array{email:string,phone:string}
	 */
	function lafka_first_order_typed_contact( $post_data = null ): array {
		static $typed = array(
			'email' => '',
			'phone' => '',
		);
		if ( is_string( $post_data ) ) {
			$fields = array();
			parse_str( $post_data, $fields );
			$typed = array(
				'email' => sanitize_email( wp_unslash( (string) ( $fields['billing_email'] ?? '' ) ) ),
				'phone' => sanitize_text_field( wp_unslash( (string) ( $fields['billing_phone'] ?? '' ) ) ),
			);
		}
		return $typed;
	}
}

if ( ! function_exists( 'lafka_is_first_order_customer' ) ) {
	/**
	 * Whether the current visitor qualifies as a first-time customer: logged
	 * in, and no counted order (lafka_first_order_counted_statuses()) by the
	 * same account, billing email or phone, other than the one this checkout
	 * is retrying, so a declined card's retry keeps the discount. Filterable.
	 *
	 * @return bool
	 */
	function lafka_is_first_order_customer(): bool {
		$eligible = false;
		if ( is_user_logged_in() && function_exists( 'wc_get_orders' ) ) {
			$user     = wp_get_current_user();
			$customer = function_exists( 'WC' ) && isset( WC()->customer ) && is_object( WC()->customer ) ? WC()->customer : null;
			$typed    = lafka_first_order_typed_contact();
			$emails   = array( (string) $user->user_email, $customer ? (string) $customer->get_billing_email() : '', $typed['email'] );
			$retry    = lafka_first_order_retry_ids();
			$ids      = lafka_first_order_identity_order_ids( (int) $user->ID, $emails, $customer ? (string) $customer->get_billing_phone() : '', $retry );
			if ( '' !== $typed['phone'] ) {
				$ids = array_merge( $ids, lafka_first_order_identity_order_ids( 0, array(), $typed['phone'], $retry ) );
			}
			$eligible = array() === $ids;
		}
		return (bool) apply_filters( 'lafka_is_first_order_customer', $eligible );
	}
}

if ( ! function_exists( 'lafka_first_order_eligible' ) ) {
	/** @return bool Feature on AND visitor qualifies. */
	function lafka_first_order_eligible(): bool {
		return lafka_first_order_discount_percent() > 0 && lafka_is_first_order_customer();
	}
}

/*
 * One holder per person. The discount is priced into the cart, then checked
 * again once the order exists and before any payment is taken, under one
 * database lock so two orders are never checked at the same time:
 *
 *   · Order placed (classic: woocommerce_checkout_order_created; block:
 *     woocommerce_store_api_checkout_order_processed; both fire before the
 *     gateway runs). The first-order part of the combined discount fee was
 *     recorded on the fee item when WooCommerce created it from the cart fee
 *     (lafka_first_order_mark_fee_item()) and is copied onto the order
 *     (_lafka_first_order_discount) for the lookups. If the
 *     person already has a settled order that counts
 *     (lafka_first_order_identity_order_ids()), it comes off this order;
 *     otherwise this order holds it and the same account's other unpaid
 *     (failed / pending) orders that carried it lose it. Another account's
 *     or a guest's order is never changed.
 *   · Order paid later (the classic Pay for order page and its submit:
 *     before_woocommerce_pay_form, woocommerce_before_pay_action; the block
 *     path's order route fires the same Store API action): a holder is paid
 *     with the discount only while the person has no other counted order.
 *
 * "Settled" means checked under the lock (_lafka_first_order_checked) or
 * older than the in-flight window: an order being placed at the same moment
 * and not yet checked is left to its own check, which then sees this one.
 * Whichever order is checked first keeps the discount, also when a block
 * draft turns pending after another order's check. The lock fails closed:
 * an order that cannot get it within 10 seconds does not keep the discount.
 */

if ( ! function_exists( 'lafka_first_order_identity_of' ) ) {
	/**
	 * Who placed an order: account id, emails and phone.
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order.
	 * @return array{0:int,1:string[],2:string}
	 */
	function lafka_first_order_identity_of( WC_Order $order ): array {
		$user   = (int) $order->get_customer_id();
		$emails = array( (string) $order->get_billing_email() );
		if ( $user > 0 ) {
			$account = get_userdata( $user );
			if ( $account ) {
				$emails[] = (string) $account->user_email;
			}
		}
		return array( $user, $emails, (string) $order->get_billing_phone() );
	}
}

if ( ! function_exists( 'lafka_first_order_settled_others' ) ) {
	/**
	 * The person's other counted orders that are settled (see above).
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order being checked.
	 * @return int[]
	 */
	function lafka_first_order_settled_others( WC_Order $order ): array {
		list( $user, $emails, $phone ) = lafka_first_order_identity_of( $order );
		$settled                       = array();
		foreach ( lafka_first_order_identity_order_ids( $user, $emails, $phone, array( $order->get_id() ) ) as $id ) {
			$other = wc_get_order( $id );
			if ( ! $other instanceof WC_Order ) {
				continue;
			}
			$created   = $other->get_date_created();
			$in_flight = '' === (string) $other->get_meta( '_lafka_first_order_checked' )
				&& $other->has_status( 'pending' )
				&& $created && ( time() - $created->getTimestamp() ) < 15 * MINUTE_IN_SECONDS;
			if ( ! $in_flight ) {
				$settled[] = $id;
			}
		}
		sort( $settled );
		return $settled;
	}
}

if ( ! function_exists( 'lafka_first_order_fee_item' ) ) {
	/**
	 * The order's combined promo discount fee (the item made from the cart fee
	 * lafka_order_discount_apply() added; marked when the order was created).
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Fee|null
	 */
	function lafka_first_order_fee_item( WC_Order $order ) {
		foreach ( $order->get_fees() as $fee ) {
			if ( '' !== (string) $fee->get_meta( '_lafka_order_discount' ) ) {
				return $fee;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'lafka_first_order_share' ) ) {
	/**
	 * How much of the order's promo discount fee is the first-order part: the
	 * split recorded on the fee item when the order was created
	 * (`_lafka_first_order`). Fail closed: a promo fee item without a recorded
	 * split is assumed to carry the configured percentage of the items after
	 * coupons (capped at the fee), so it can be taken out if the customer is
	 * not eligible.
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order.
	 * @return float
	 */
	function lafka_first_order_share( WC_Order $order ): float {
		$fee = lafka_first_order_fee_item( $order );
		if ( null === $fee || (float) $fee->get_total() >= 0 ) {
			return 0.0;
		}
		if ( $fee->meta_exists( '_lafka_first_order' ) ) {
			return max( 0.0, (float) $fee->get_meta( '_lafka_first_order' ) );
		}
		$base = max( 0.0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
		return min( abs( (float) $fee->get_total() ), lafka_first_order_discount_amount( $base, lafka_first_order_discount_percent() ) );
	}
}

if ( ! function_exists( 'lafka_first_order_strip' ) ) {
	/**
	 * Take the first-order part out of the order's promo discount fee item,
	 * recalculate the totals and say why in an order note.
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order.
	 * @param string   $why   Order note.
	 * @return void
	 */
	function lafka_first_order_strip( WC_Order $order, string $why ): void {
		$fee   = lafka_first_order_fee_item( $order );
		$share = lafka_first_order_share( $order );
		if ( null === $fee || $share <= 0 ) {
			return;
		}
		$left  = round( (float) $fee->get_total() + $share, wc_get_price_decimals() );
		$label = (string) $fee->get_meta( '_lafka_first_order_label' );
		if ( $left >= 0 ) {
			$order->remove_item( $fee->get_id() );
		} else {
			if ( '' !== $label ) {
				$name = trim( str_replace( array( ' + ' . $label, $label . ' + ', $label ), '', $fee->get_name() ) );
				$fee->set_name( '' !== $name ? $name : __( 'Discount', 'lafka-plugin' ) );
			}
			$fee->set_amount( (string) $left );
			$fee->set_total( (string) $left );
			$fee->update_meta_data( '_lafka_first_order', '0' );
			$fee->save();
		}
		$order->delete_meta_data( '_lafka_first_order_discount' );
		$order->calculate_totals( true );
		$order->add_order_note( $why );
		$order->save();
	}
}

if ( ! function_exists( 'lafka_first_order_mark_fee_item' ) ) {
	add_action( 'woocommerce_checkout_create_order_fee_item', 'lafka_first_order_mark_fee_item', 10, 3 );
	/**
	 * When WooCommerce turns the cart fees into order fee items (classic
	 * checkout and Store API alike, WC_Checkout::create_order_fee_lines()),
	 * mark the promo discount item and record its first-order part from the
	 * computation that built the cart fee.
	 *
	 * @since 10.4.0
	 * @param mixed $item    Order fee item.
	 * @param mixed $fee_key Cart fee key.
	 * @param mixed $fee     Cart fee.
	 * @return void
	 */
	function lafka_first_order_mark_fee_item( $item, $fee_key = '', $fee = null ): void {
		unset( $fee_key );
		if ( ! $item instanceof WC_Order_Item_Fee || ! is_object( $fee ) || ! isset( $fee->lafka_order_discount ) ) {
			return;
		}
		$item->add_meta_data( '_lafka_order_discount', '1', true );
		$item->add_meta_data( '_lafka_first_order', (string) (float) ( $fee->lafka_first_order ?? 0 ), true );
		$item->add_meta_data( '_lafka_first_order_label', (string) ( $fee->lafka_first_order_label ?? '' ), true );
	}
}

if ( ! function_exists( 'lafka_first_order_record' ) ) {
	/**
	 * Copy the first-order share of a just-placed order onto the order (for
	 * the lookups of other orders) from its fee item.
	 *
	 * @since 10.4.0
	 * @param WC_Order $order Order.
	 * @return void
	 */
	function lafka_first_order_record( WC_Order $order ): void {
		$share = lafka_first_order_share( $order );
		if ( $share > 0 ) {
			$order->update_meta_data( '_lafka_first_order_discount', $share );
		} else {
			$order->delete_meta_data( '_lafka_first_order_discount' );
		}
		$order->save();
	}
}

if ( ! function_exists( 'lafka_first_order_check' ) ) {
	/**
	 * The check itself, under the lock (the order was just placed, or is
	 * about to be paid).
	 *
	 * @since 10.4.0
	 * @param WC_Order $order   Order.
	 * @return void
	 */
	function lafka_first_order_check( WC_Order $order ): void {
		global $wpdb;
		$locked = 1 === (int) $wpdb->get_var( "SELECT GET_LOCK('lafka_first_order', 10)" );
		try {
			lafka_first_order_record( $order );
			$holds  = (float) $order->get_meta( '_lafka_first_order_discount' ) > 0;
			$others = $holds && $locked ? lafka_first_order_settled_others( $order ) : array();
			if ( $holds && ! $locked ) {
				// Fail closed: without the lock the check cannot be trusted.
				lafka_first_order_strip( $order, __( 'First-order discount removed: the order could not be checked (the store was busy).', 'lafka-plugin' ) );
			} elseif ( $holds && array() !== $others ) {
				lafka_first_order_strip(
					$order,
					sprintf(
						/* translators: %d: the customer's other order number */
						__( 'First-order discount removed: the customer already has order #%d.', 'lafka-plugin' ),
						(int) $others[0]
					)
				);
			} elseif ( $holds && $order->get_customer_id() > 0 ) {
				// Only the same account's own unpaid orders give the discount up.
				$unpaid = wc_get_orders(
					array(
						'customer_id' => $order->get_customer_id(),
						'status'      => array( 'wc-failed', 'wc-pending' ),
						'exclude'     => array( $order->get_id() ),
						'limit'       => 50,
						'return'      => 'ids',
						'type'        => 'shop_order',
					)
				);
				foreach ( $unpaid as $id ) {
					$other = wc_get_order( $id );
					if ( $other instanceof WC_Order && (float) $other->get_meta( '_lafka_first_order_discount' ) > 0 ) {
						lafka_first_order_strip(
							$other,
							sprintf(
								/* translators: %d: the order that now has the discount */
								__( 'First-order discount removed: it is on order #%d now.', 'lafka-plugin' ),
								$order->get_id()
							)
						);
					}
				}
			}
			$order->update_meta_data( '_lafka_first_order_checked', (string) time() );
			$order->save();
		} finally {
			if ( $locked ) {
				$wpdb->get_var( "SELECT RELEASE_LOCK('lafka_first_order')" );
			}
		}
	}
}

if ( ! function_exists( 'lafka_first_order_on_placed' ) ) {
	add_action( 'woocommerce_checkout_order_created', 'lafka_first_order_on_placed' );
	/**
	 * Classic checkout: the order was created (or the failed / pending order
	 * resumed) from the cart, before payment.
	 *
	 * @since 10.4.0
	 * @param mixed $order Order.
	 * @return void
	 */
	function lafka_first_order_on_placed( $order ): void {
		if ( $order instanceof WC_Order ) {
			lafka_first_order_check( $order );
		}
	}
}

if ( ! function_exists( 'lafka_first_order_on_store_api' ) ) {
	add_action( 'woocommerce_store_api_checkout_order_processed', 'lafka_first_order_on_store_api' );
	/**
	 * Block checkout (and the Store API order-pay route), before payment.
	 *
	 * @since 10.4.0
	 * @param mixed $order Order.
	 * @return void
	 */
	function lafka_first_order_on_store_api( $order ): void {
		if ( $order instanceof WC_Order ) {
			lafka_first_order_check( $order );
		}
	}
}

if ( ! function_exists( 'lafka_first_order_on_pay' ) ) {
	add_action( 'before_woocommerce_pay_form', 'lafka_first_order_on_pay' );
	add_action( 'woocommerce_before_pay_action', 'lafka_first_order_on_pay' );
	/**
	 * Pay for order (page and submit): a holder keeps the discount only while
	 * the person has no other counted order.
	 *
	 * @since 10.4.0
	 * @param mixed $order Order.
	 * @return void
	 */
	function lafka_first_order_on_pay( $order ): void {
		if ( $order instanceof WC_Order && lafka_first_order_share( $order ) > 0 ) {
			lafka_first_order_check( $order );
		}
	}
}

if ( ! function_exists( 'lafka_first_order_on_paid' ) ) {
	add_action( 'woocommerce_order_status_changed', 'lafka_first_order_on_paid', 10, 3 );
	/**
	 * When an order that holds the discount is paid (processing / completed),
	 * look for another order of the same person (account, billing email or
	 * phone) already paid with it. Two unpaid orders from different accounts
	 * with the same email or phone can both hold it until one is paid; the
	 * paid amount is never changed silently: the later order gets an order
	 * note and is listed for staff (lafka_first_order_review_notice()).
	 *
	 * @since 10.4.0
	 * @param int    $order_id Order id.
	 * @param string $from     Old status.
	 * @param string $to       New status.
	 * @return void
	 */
	function lafka_first_order_on_paid( $order_id, $from = '', $to = '' ): void {
		unset( $from );
		if ( ! in_array( (string) $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || (float) $order->get_meta( '_lafka_first_order_discount' ) <= 0 || '' !== (string) $order->get_meta( '_lafka_first_order_duplicate' ) ) {
			return;
		}
		list( $user, $emails, $phone ) = lafka_first_order_identity_of( $order );
		foreach ( lafka_first_order_identity_order_ids( $user, $emails, $phone, array( $order->get_id() ) ) as $id ) {
			$other = wc_get_order( $id );
			if ( $other instanceof WC_Order && $other->has_status( array( 'processing', 'completed', 'refunded' ) ) && (float) $other->get_meta( '_lafka_first_order_discount' ) > 0 ) {
				$order->update_meta_data( '_lafka_first_order_duplicate', (string) $other->get_id() );
				$review = array_map( 'absint', (array) get_option( 'lafka_first_order_review', array() ) );
				update_option( 'lafka_first_order_review', array_values( array_unique( array_merge( $review, array( $order->get_id() ) ) ) ), false );
				$order->add_order_note(
					sprintf(
						/* translators: 1: discount amount, 2: the other order number */
						__( 'Check this order: it was paid with the first-order discount (%1$s), but order #%2$d by the same customer (account, email or phone) was already paid with it. The amount was not changed.', 'lafka-plugin' ),
						lafka_price_plain( (float) $order->get_meta( '_lafka_first_order_discount' ) ),
						$other->get_id()
					)
				);
				$order->save();
				return;
			}
		}
	}
}

if ( ! function_exists( 'lafka_first_order_review_notice' ) ) {
	add_action( 'admin_notices', 'lafka_first_order_review_notice' );
	/**
	 * Staff notice: orders flagged by lafka_first_order_on_paid() that nobody
	 * has marked reviewed yet (the `lafka_first_order_review` option lists them).
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_first_order_review_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$ids = array_slice( array_map( 'absint', (array) get_option( 'lafka_first_order_review', array() ) ), 0, 5 );
		if ( array() === $ids ) {
			return;
		}
		$links = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order ) {
				$links[] = sprintf(
					'<a href="%1$s">#%2$d</a> (<a href="%3$s">%4$s</a>)',
					esc_url( $order->get_edit_order_url() ),
					(int) $id,
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lafka_first_order_reviewed&order=' . (int) $id ), 'lafka_first_order_reviewed_' . (int) $id ) ),
					esc_html__( 'mark reviewed', 'lafka-plugin' )
				);
			}
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s %2$s</p></div>',
			esc_html__( 'First-order discount used twice by the same customer (see the order note):', 'lafka-plugin' ),
			wp_kses_post( implode( ', ', $links ) )
		);
	}
}

if ( ! function_exists( 'lafka_first_order_mark_reviewed' ) ) {
	add_action( 'admin_post_lafka_first_order_reviewed', 'lafka_first_order_mark_reviewed' );
	/**
	 * "Mark reviewed" on the staff notice.
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_first_order_mark_reviewed(): void {
		$id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		check_admin_referer( 'lafka_first_order_reviewed_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'lafka-plugin' ), 403 );
		}
		$order = wc_get_order( $id );
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( '_lafka_first_order_reviewed', (string) get_current_user_id() );
			update_option( 'lafka_first_order_review', array_values( array_diff( array_map( 'absint', (array) get_option( 'lafka_first_order_review', array() ) ), array( $id ) ) ), false );
			$order->add_order_note( __( 'First-order discount check marked reviewed.', 'lafka-plugin' ), 0, true );
			$order->save();
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}

if ( ! function_exists( 'lafka_first_order_discount_amount' ) ) {
	/**
	 * Pure discount math (testable): percent of a subtotal, 2dp, never negative.
	 *
	 * @param float $subtotal
	 * @param float $percent
	 * @return float
	 */
	function lafka_first_order_discount_amount( float $subtotal, float $percent ): float {
		if ( $percent <= 0 || $subtotal <= 0 ) {
			return 0.0;
		}
		return round( $subtotal * ( min( 100.0, $percent ) / 100 ), 2 );
	}
}

if ( ! function_exists( 'lafka_first_order_discount_component' ) ) {
	add_filter( 'lafka_order_discount_components', 'lafka_first_order_discount_component', 10, 1 );
	/**
	 * Feed the first-order discount into the shared order-discount coordinator
	 * (lafka_order_discount_apply) instead of adding its own cart fee. Returning a
	 * percentage component lets the coordinator stack it sequentially with the
	 * other promos under ONE combined, capped fee — so it can never push the order
	 * to a free/negative total on its own or alongside slow-day / combo.
	 *
	 * @param array         $components Discount components collected so far.
	 * @param \WC_Cart|null $cart      Current cart (unused; eligibility is contextual).
	 * @return array
	 */
	function lafka_first_order_discount_component( $components ) {
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		if ( ! lafka_first_order_eligible() ) {
			return $components;
		}
		$percent = lafka_first_order_discount_percent();
		if ( $percent > 0 ) {
			$components[] = array(
				'source' => 'first_order',
				'type'   => 'percent',
				'value'  => $percent,
				'label'  => sprintf(
					/* translators: %s = discount percent, e.g. 15 */
					__( 'First-order discount (%s%% off)', 'lafka-plugin' ),
					(string) ( (float) $percent )
				),
			);
		}
		return $components;
	}
}

if ( ! function_exists( 'lafka_order_discount_combined' ) ) {
	/**
	 * Shared, pure aggregation for all order-level percentage/fixed promos.
	 *
	 * Percentages are applied SEQUENTIALLY against a diminishing balance (so two
	 * 60%-off promos discount 60%, then 60% of the remainder — never 120% of the
	 * raw subtotal), the fixed amount is then taken from what is left, and the
	 * final combined discount is clamped so it can never exceed the payable base
	 * (subtotal minus any coupon discount already applied). This is what prevents
	 * stacked promos — or promos plus coupons — from clamping the total to $0.
	 *
	 * @param float   $base               Pre-coupon subtotal the promos discount.
	 * @param float[] $percents           Percentage promos (each treated as 0–100).
	 * @param float   $fixed              Combined fixed-amount promos.
	 * @param float   $already_discounted Coupon discount already applied to the base.
	 * @return float Non-negative combined discount, 2dp, never above the payable base.
	 */
	function lafka_order_discount_combined( float $base, array $percents, float $fixed = 0.0, float $already_discounted = 0.0 ): float {
		$base = max( 0.0, $base );
		if ( $base <= 0.0 ) {
			return 0.0;
		}
		$remaining = $base;
		foreach ( $percents as $percent ) {
			$percent = (float) $percent;
			if ( $percent <= 0.0 ) {
				continue;
			}
			$percent = min( 100.0, $percent );
			$cut     = round( $remaining * ( $percent / 100 ), 2 );
			if ( $cut > $remaining ) {
				$cut = $remaining;
			}
			$remaining -= $cut;
		}
		$fixed = max( 0.0, $fixed );
		if ( $fixed > 0.0 ) {
			$remaining -= min( $fixed, $remaining );
		}
		$discount = round( $base - $remaining, 2 );
		// Never discount more than the customer still owes (subtotal minus coupons).
		$payable_cap = max( 0.0, $base - max( 0.0, $already_discounted ) );
		if ( $discount > $payable_cap ) {
			$discount = $payable_cap;
		}
		return max( 0.0, round( $discount, 2 ) );
	}
}

if ( ! function_exists( 'lafka_order_discount_tax_class' ) ) {
	/**
	 * Tax class to assign to the combined order-discount fee.
	 *
	 * The fee is added TAXABLE (see lafka_order_discount_apply) so its negative
	 * tax nets out the tax WooCommerce charged on the un-discounted line
	 * subtotals — i.e. so the discount reduces the TAXABLE base, matching the
	 * BOGO module (class-lafka-promotions.php), which lowers the base via
	 * set_price(). The discount is spread proportionally across the whole cart,
	 * so the single fee carries the tax class holding the largest share of the
	 * (ex-tax) cart subtotal. Only taxable line items count toward that share.
	 *
	 * For the typical single-tax-class cart this is exact; a cart that genuinely
	 * mixes tax classes cannot be netted exactly by one fee — it is approximated
	 * via the dominant class, and operators can pin an exact class through the
	 * `lafka_order_discount_tax_class` filter.
	 *
	 * @param \WC_Cart|object $cart Current cart.
	 * @return string WooCommerce tax-class slug ('' = standard rate).
	 */
	function lafka_order_discount_tax_class( $cart ): string {
		$by_class = array();
		if ( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ) {
			foreach ( (array) $cart->get_cart() as $item ) {
				if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
					continue;
				}
				$product = $item['data'];
				if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
					continue;
				}
				$class              = method_exists( $product, 'get_tax_class' ) ? (string) $product->get_tax_class() : '';
				$line               = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
				$by_class[ $class ] = ( $by_class[ $class ] ?? 0.0 ) + $line;
			}
		}
		if ( array() !== $by_class ) {
			arsort( $by_class );
		}
		$tax_class = array() === $by_class ? '' : (string) array_key_first( $by_class );
		/**
		 * Filter the tax class assigned to the combined order-discount fee.
		 * Lets operators with mixed-tax-class carts pin an exact class so the
		 * fee's negative tax nets the line tax out precisely.
		 *
		 * @param string          $tax_class Resolved tax-class slug ('' = standard).
		 * @param \WC_Cart|object $cart      Current cart.
		 */
		return (string) apply_filters( 'lafka_order_discount_tax_class', $tax_class, $cart );
	}
}

if ( ! function_exists( 'lafka_order_discount_apply' ) ) {
	add_action( 'woocommerce_cart_calculate_fees', 'lafka_order_discount_apply' );
	/**
	 * Order-level discount coordinator.
	 *
	 * Replaces the former per-module cart-fee hooks (first-order, slow-day, combo
	 * each added their own negative fee off the raw subtotal with only an
	 * individual cap, so they stacked additively and could exceed 100% → free
	 * orders). It gathers every enabled promo via the
	 * `lafka_order_discount_components` filter, aggregates them with
	 * lafka_order_discount_combined(), and adds ONE capped negative fee. This is
	 * the single place that enforces the combined cap, so no configuration of the
	 * individual promos can drive an order to a free/negative total.
	 *
	 * Tax treatment (the single agreed treatment for ALL order-level promos):
	 * the combined fee is added TAXABLE with the cart's dominant tax class so its
	 * negative tax nets out the tax WooCommerce charged on the un-discounted line
	 * subtotals — i.e. the discount reduces the TAXABLE base, matching the BOGO
	 * module (class-lafka-promotions.php), which lowers the base via set_price().
	 * The old non-taxable fee left tax on the full pre-discount subtotal and so
	 * over-charged the order in jurisdictions where discounts lower the taxable
	 * base. One fee carries one tax class, so this is exact for the common
	 * single-tax-class cart; carts that genuinely mix tax classes are netted via
	 * the dominant class — see lafka_order_discount_tax_class() and its filter.
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	function lafka_order_discount_apply( $cart ) {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! is_object( $cart ) ) {
			return;
		}
		$fee = lafka_order_discount_fee( $cart );
		if ( null !== $fee && method_exists( $cart, 'fees_api' ) ) {
			// The split travels with the cart fee to the order fee item
			// (lafka_first_order_mark_fee_item()).
			$cart->fees_api()->add_fee(
				array(
					'name'                    => $fee['label'],
					'amount'                  => -$fee['amount'],
					'taxable'                 => $fee['taxable'],
					'tax_class'               => $fee['tax_class'],
					'lafka_order_discount'    => true,
					'lafka_first_order'       => $fee['first_order'],
					'lafka_first_order_label' => $fee['first_order_label'],
				)
			);
		}
	}
}

if ( ! function_exists( 'lafka_order_discount_fee' ) ) {
	/**
	 * The combined order-level discount for a cart: its label, amount, tax
	 * treatment and first-order part, or null when no promo applies.
	 *
	 * @since 10.4.0
	 * @param \WC_Cart $cart Cart.
	 * @return array{label:string,amount:float,taxable:bool,tax_class:string,first_order:float,first_order_label:string}|null
	 */
	function lafka_order_discount_fee( $cart ): ?array {
		$components = apply_filters( 'lafka_order_discount_components', array(), $cart );
		if ( ! is_array( $components ) || array() === $components ) {
			return null;
		}
		$percents      = array();
		$fixed         = 0.0;
		$labels        = array();
		$rest_percents = array();
		$rest_fixed    = 0.0;
		$fo_label      = '';
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$value = isset( $component['value'] ) ? (float) $component['value'] : 0.0;
			if ( $value <= 0.0 ) {
				continue;
			}
			$first = 'first_order' === ( $component['source'] ?? '' );
			if ( isset( $component['type'] ) && 'fixed' === $component['type'] ) {
				$fixed      += $value;
				$rest_fixed += $first ? 0.0 : $value;
			} else {
				$percents[] = $value;
				if ( ! $first ) {
					$rest_percents[] = $value;
				}
			}
			if ( ! empty( $component['label'] ) ) {
				$labels[] = (string) $component['label'];
				$fo_label = $first ? (string) $component['label'] : $fo_label;
			}
		}
		if ( array() === $percents && $fixed <= 0.0 ) {
			return null;
		}
		$already = 0.0;
		if ( method_exists( $cart, 'get_discount_total' ) ) {
			$already = (float) $cart->get_discount_total();
		}
		$amount = lafka_order_discount_combined( (float) $cart->get_subtotal(), $percents, $fixed, $already );
		if ( $amount <= 0.0 ) {
			return null;
		}
		// The first-order part: the combined amount less what the other promos give alone.
		$rest  = array() === $rest_percents && $rest_fixed <= 0.0 ? 0.0 : lafka_order_discount_combined( (float) $cart->get_subtotal(), $rest_percents, $rest_fixed, $already );
		$label = array() === $labels ? __( 'Discount', 'lafka-plugin' ) : implode( ' + ', $labels );
		/**
		 * Filter the single combined discount fee label shown in the cart/checkout.
		 *
		 * @param string        $label  Default label (active-promo labels joined by " + ").
		 * @param string[]      $labels Individual active-promo labels.
		 * @param \WC_Cart|null $cart   Current cart.
		 */
		$label = (string) apply_filters( 'lafka_order_discount_label', $label, $labels, $cart );
		// Add the discount as a TAXABLE negative fee carrying the cart's dominant
		// tax class, so its negative tax nets out the tax charged on the
		// un-discounted line subtotals. The discount thus reduces the taxable
		// base, consistent with BOGO; a non-taxable fee (the old behaviour) left
		// tax on the full pre-discount subtotal and over-charged the order.
		return array(
			'label'             => $label,
			'amount'            => $amount,
			'taxable'           => function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : true,
			'tax_class'         => lafka_order_discount_tax_class( $cart ),
			'first_order'       => '' !== $fo_label ? max( 0.0, round( $amount - $rest, 2 ) ) : 0.0,
			'first_order_label' => $fo_label,
		);
	}
}
