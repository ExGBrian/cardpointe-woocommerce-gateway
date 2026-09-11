<?php
/**
 * Order screen integration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Admin;

use ParadoxSolutions\CardPointe\Compatibility;
use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Gateway\OrderMeta;
use ParadoxSolutions\CardPointe\Gateway\TransactionManager;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box, order actions, AJAX capture/void, and status-change automation.
 */
final class OrderActions {

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_paradox_cardpointe_capture', array( $this, 'action_capture' ) );
		add_action( 'woocommerce_order_action_paradox_cardpointe_void', array( $this, 'action_void' ) );

		add_action( 'wp_ajax_paradox_cardpointe_order_capture', array( $this, 'ajax_capture' ) );
		add_action( 'wp_ajax_paradox_cardpointe_order_void', array( $this, 'ajax_void' ) );
		add_action( 'wp_ajax_paradox_cardpointe_order_inquire', array( $this, 'ajax_inquire' ) );

		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_capture_on_status' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_capture_on_status' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'maybe_void_on_status' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Meta box
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the meta box on the order screen (HPOS and legacy).
	 *
	 * @param string $screen_id     Screen / post type.
	 * @param mixed  $post_or_order Post or order.
	 */
	public function add_meta_box( $screen_id, $post_or_order = null ) {
		$order_screen = Compatibility::order_screen_id();
		if ( $screen_id !== $order_screen && 'shop_order' !== $screen_id ) {
			return;
		}
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order || ! Plugin::is_our_gateway( $order->get_payment_method() ) ) {
			return;
		}
		add_meta_box(
			'paradox-cardpointe',
			__( 'CardPointe', 'paradox-cardpointe-gateway-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			$screen_id,
			'side',
			'default'
		);
	}

	/**
	 * Renders the meta box.
	 *
	 * @param mixed $post_or_order Post or order.
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		Plugin::template(
			'admin/order-meta-box.php',
			array(
				'order' => $order,
				'info'  => $this->summary( $order ),
			)
		);
	}

	/**
	 * Builds the data shown in the meta box.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function summary( \WC_Order $order ): array {
		$retref     = OrderMeta::retref( $order );
		$captured   = OrderMeta::is_captured( $order );
		$voided     = OrderMeta::is_voided( $order );
		$pending    = '' !== (string) OrderMeta::get( $order, OrderMeta::PENDING_ORDERID );
		$setlstat   = (string) OrderMeta::get( $order, OrderMeta::SETLSTAT );
		$authorized = (float) OrderMeta::get( $order, OrderMeta::AMOUNT_AUTHORIZED, 0 );
		$stored     = OrderMeta::stored_payment_method( $order );
		$currency   = array( 'currency' => $order->get_currency() );

		if ( $voided ) {
			$state = __( 'Voided', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( $captured ) {
			$state = '' !== $setlstat ? sprintf( /* translators: %s: settlement status */ __( 'Captured (%s)', 'paradox-cardpointe-gateway-for-woocommerce' ), $setlstat ) : __( 'Captured', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( '' !== $retref ) {
			$state = __( 'Authorized, not captured', 'paradox-cardpointe-gateway-for-woocommerce' );
		} elseif ( OrderMeta::has_stored_payment_method( $order ) ) {
			$state = __( 'Payment method vaulted, not charged', 'paradox-cardpointe-gateway-for-woocommerce' );
		} else {
			$state = $pending ? __( 'Pending / unknown', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'No transaction', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		$method = '';
		if ( 'echeck' === $stored['type'] ) {
			$method = ( 'ESAV' === $stored['accttype'] ? __( 'Savings', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Checking', 'paradox-cardpointe-gateway-for-woocommerce' ) ) . ( '' !== $stored['last4'] ? ' ****' . $stored['last4'] : '' );
		} elseif ( '' !== $stored['last4'] || '' !== $stored['brand'] ) {
			$method = ( '' !== $stored['brand'] ? CardTypes::label( $stored['brand'] ) : __( 'Card', 'paradox-cardpointe-gateway-for-woocommerce' ) ) . ( '' !== $stored['last4'] ? ' ****' . $stored['last4'] : '' );
		}

		$rows = array(
			__( 'Retref', 'paradox-cardpointe-gateway-for-woocommerce' )       => $retref,
			__( 'Auth code', 'paradox-cardpointe-gateway-for-woocommerce' )    => (string) OrderMeta::get( $order, OrderMeta::AUTHCODE ),
			__( 'Authorized', 'paradox-cardpointe-gateway-for-woocommerce' )   => $authorized > 0 ? wp_strip_all_tags( wc_price( $authorized, $currency ) ) : '',
			__( 'Captured', 'paradox-cardpointe-gateway-for-woocommerce' )     => $captured ? wp_strip_all_tags( wc_price( (float) OrderMeta::get( $order, OrderMeta::CAPTURED_AMOUNT, $authorized ), $currency ) ) : '',
			__( 'Method', 'paradox-cardpointe-gateway-for-woocommerce' )       => $method,
			__( 'AVS', 'paradox-cardpointe-gateway-for-woocommerce' )          => '' !== $retref ? OrderMeta::avs_text( (string) OrderMeta::get( $order, OrderMeta::AVSRESP ) ) : '',
			__( 'CVV', 'paradox-cardpointe-gateway-for-woocommerce' )          => '' !== $retref && 'card' === $stored['type'] ? OrderMeta::cvv_text( (string) OrderMeta::get( $order, OrderMeta::CVVRESP ) ) : '',
			__( 'Profile', 'paradox-cardpointe-gateway-for-woocommerce' )      => '' !== $stored['profile_id'] ? $stored['profile_id'] . ( '' !== $stored['acct_id'] ? '/' . $stored['acct_id'] : '' ) : '',
			__( 'Gateway order', 'paradox-cardpointe-gateway-for-woocommerce' ) => (string) OrderMeta::get( $order, OrderMeta::ORDERID ),
		);

		$refunds = array();
		foreach ( OrderMeta::refunds( $order ) as $record ) {
			$refunds[] = sprintf(
				'%s %s (%s) %s',
				'void' === ( $record['type'] ?? '' ) ? __( 'Void', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Refund', 'paradox-cardpointe-gateway-for-woocommerce' ),
				wp_strip_all_tags( wc_price( (float) ( $record['amount'] ?? 0 ), $currency ) ),
				(string) ( $record['retref'] ?? '' ),
				isset( $record['date'] ) ? wp_date( get_option( 'date_format' ), strtotime( $record['date'] ) ) : ''
			);
		}

		$expires_note  = '';
		$authorized_at = (int) OrderMeta::get( $order, OrderMeta::AUTHORIZED_AT, 0 );
		if ( '' !== $retref && ! $captured && ! $voided && $authorized_at > 0 ) {
			$expires_note = sprintf(
				/* translators: %s: date */
				__( 'Authorizations usually expire after 7 days (around %s). Capture before then.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				wp_date( get_option( 'date_format' ), $authorized_at + 7 * DAY_IN_SECONDS )
			);
		}

		$remaining = max( 0, $authorized - (float) $order->get_total_refunded() );

		return array(
			'environment'    => (string) OrderMeta::get( $order, OrderMeta::ENVIRONMENT, 'production' ),
			'state_label'    => $state,
			'rows'           => $rows,
			'refunds'        => $refunds,
			'pending'        => $pending,
			'has_retref'     => '' !== $retref,
			'can_capture'    => '' !== $retref && ! $captured && ! $voided,
			'can_void'       => '' !== $retref && ! $voided && ( ! $captured || in_array( $setlstat, array( '', 'Queued for Capture', 'Authorized' ), true ) ),
			'capture_amount' => wc_format_localized_price( $remaining > 0 ? $remaining : $authorized ),
			'expires_note'   => $expires_note,
		);
	}

	/* ---------------------------------------------------------------------
	 * Order actions dropdown
	 * ------------------------------------------------------------------ */

	/**
	 * Adds capture/void to the order actions dropdown.
	 *
	 * @param array          $actions Actions.
	 * @param \WC_Order|null $order   Order.
	 * @return array
	 */
	public function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			$order = $this->current_order();
		}
		if ( ! $order || ! Plugin::is_our_gateway( $order->get_payment_method() ) ) {
			return $actions;
		}
		$retref = OrderMeta::retref( $order );
		if ( '' === $retref || OrderMeta::is_voided( $order ) ) {
			return $actions;
		}
		if ( ! OrderMeta::is_captured( $order ) ) {
			$actions['paradox_cardpointe_capture'] = __( 'CardPointe: capture authorization', 'paradox-cardpointe-gateway-for-woocommerce' );
			$actions['paradox_cardpointe_void']    = __( 'CardPointe: void authorization', 'paradox-cardpointe-gateway-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Capture from the order actions dropdown.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function action_capture( $order ) {
		try {
			( new TransactionManager( Plugin::gateway_for_order( $order ) ) )->capture( $order );
		} catch ( \Exception $e ) {
			$order->add_order_note( __( 'CardPointe capture failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
		}
	}

	/**
	 * Void from the order actions dropdown.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function action_void( $order ) {
		try {
			( new TransactionManager( Plugin::gateway_for_order( $order ) ) )->void( $order );
			if ( ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				$order->update_status( 'cancelled', __( 'Order cancelled after CardPointe void.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			}
		} catch ( \Exception $e ) {
			$order->add_order_note( __( 'CardPointe void failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
		}
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * AJAX: capture.
	 */
	public function ajax_capture() {
		$order  = $this->ajax_order();
		$amount = isset( $_POST['amount'] ) ? wc_format_decimal( wp_unslash( $_POST['amount'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in ajax_order().
		try {
			$manager  = new TransactionManager( Plugin::gateway_for_order( $order ) );
			$response = $manager->capture( $order, '' !== $amount ? (float) $amount : null );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: amount */
						__( 'Captured %s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						wp_strip_all_tags( wc_price( (float) $response->string( 'amount', $amount ), array( 'currency' => $order->get_currency() ) ) )
					),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: void.
	 */
	public function ajax_void() {
		$order = $this->ajax_order();
		try {
			$manager = new TransactionManager( Plugin::gateway_for_order( $order ) );
			$manager->void( $order );
			if ( ! OrderMeta::is_captured( $order ) && ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				$order->update_status( 'cancelled', __( 'Order cancelled after CardPointe void.', 'paradox-cardpointe-gateway-for-woocommerce' ) );
			}
			wp_send_json_success( array( 'message' => __( 'Transaction voided.', 'paradox-cardpointe-gateway-for-woocommerce' ) ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: refresh status / reconcile pending.
	 */
	public function ajax_inquire() {
		$order = $this->ajax_order();
		try {
			$manager = new TransactionManager( Plugin::gateway_for_order( $order ) );
			if ( '' !== (string) OrderMeta::get( $order, OrderMeta::PENDING_ORDERID ) && '' === OrderMeta::retref( $order ) ) {
				$found = $manager->reconcile_pending( $order );
				wp_send_json_success(
					array(
						'message' => $found
							? __( 'An approved transaction was found and applied to the order.', 'paradox-cardpointe-gateway-for-woocommerce' )
							: __( 'No approved transaction was found for the pending attempt.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					)
				);
			}
			$response = $manager->inquire( $order );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: settlement status, 2: voidable, 3: refundable */
						__( 'Status: %1$s. Voidable: %2$s. Refundable: %3$s.', 'paradox-cardpointe-gateway-for-woocommerce' ),
						$response->setlstat() ?: __( 'unknown', 'paradox-cardpointe-gateway-for-woocommerce' ),
						$response->string( 'voidable', '-' ),
						$response->string( 'refundable', '-' )
					),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Verifies the AJAX request and returns the order.
	 */
	private function ajax_order(): \WC_Order {
		check_ajax_referer( 'paradox_cardpointe_order_action', 'nonce' );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || ! Plugin::is_our_gateway( $order->get_payment_method() ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'paradox-cardpointe-gateway-for-woocommerce' ) ), 404 );
		}
		return $order;
	}

	/* ---------------------------------------------------------------------
	 * Status automation
	 * ------------------------------------------------------------------ */

	/**
	 * Captures authorized orders when they move to Processing/Completed.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order.
	 */
	public function maybe_capture_on_status( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $order );
		if ( ! $gateway || ! $gateway->capture_on_status_change() ) {
			return;
		}
		if ( '' === OrderMeta::retref( $order ) || OrderMeta::is_captured( $order ) || OrderMeta::is_voided( $order ) ) {
			return;
		}
		/**
		 * Filters whether to auto-capture on this status change.
		 *
		 * @param bool      $capture Default true.
		 * @param \WC_Order $order   Order.
		 */
		if ( ! apply_filters( 'paradox_cardpointe_capture_on_status_change', true, $order ) ) {
			return;
		}
		try {
			( new TransactionManager( $gateway ) )->capture( $order );
		} catch ( \Exception $e ) {
			$order->add_order_note( __( 'CardPointe automatic capture failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
		}
	}

	/**
	 * Voids un-captured authorizations when an order is cancelled.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order.
	 */
	public function maybe_void_on_status( $order_id, $order = null ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$gateway = Plugin::gateway_for_order( $order );
		if ( ! $gateway || ! $gateway->capture_on_status_change() ) {
			return;
		}
		if ( '' === OrderMeta::retref( $order ) || OrderMeta::is_captured( $order ) || OrderMeta::is_voided( $order ) ) {
			return;
		}
		try {
			( new TransactionManager( $gateway ) )->void( $order );
		} catch ( \Exception $e ) {
			$order->add_order_note( __( 'CardPointe automatic void failed:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . $e->getMessage() );
			$order->save();
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Resolves a post or order argument to a WC_Order.
	 *
	 * @param mixed $post_or_order Post, order, or null.
	 * @return \WC_Order|null
	 */
	private function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof \WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof \WC_Order ? $order : null;
		}
		return $this->current_order();
	}

	/**
	 * Order being edited, from the request.
	 *
	 * @return \WC_Order|null
	 */
	private function current_order() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id = 0;
		if ( isset( $_GET['id'] ) ) {
			$id = absint( $_GET['id'] );
		} elseif ( isset( $_GET['post'] ) ) {
			$id = absint( $_GET['post'] );
		}
		// phpcs:enable
		if ( ! $id ) {
			return null;
		}
		$order = wc_get_order( $id );
		return $order instanceof \WC_Order ? $order : null;
	}
}
