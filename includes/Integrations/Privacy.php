<?php
/**
 * Privacy (GDPR) integration.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Integrations;

use ParadoxSolutions\CardPointe\Gateway\OrderMeta;
use ParadoxSolutions\CardPointe\Plugin;
use ParadoxSolutions\CardPointe\Settings\Credentials;
use ParadoxSolutions\CardPointe\Tokens\ProfileService;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter/eraser for CardPointe order data and vault references.
 *
 * Saved payment tokens themselves are exported and erased by WooCommerce core.
 */
final class Privacy {

	const RETENTION_OPTION = 'paradox_cardpointe_privacy_retention';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_filter( 'woocommerce_get_settings_account', array( $this, 'retention_setting' ) );
		add_action( 'admin_init', array( $this, 'privacy_policy_content' ) );
	}

	/**
	 * Suggested privacy policy text.
	 */
	public function privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . __( 'When you pay with CardPointe, your card or bank account details are entered in a secure form hosted by CardPointe (Fiserv) and are never stored on this site. We store a payment reference, the last four digits of your account, the card brand, and, if you choose to save a payment method, a vault reference that lets us charge it later. See the CardPointe privacy policy for how they process your data.', 'paradox-cardpointe-gateway-for-woocommerce' ) . '</p>';
		wp_add_privacy_policy_content( __( 'CardPointe Payment Gateway', 'paradox-cardpointe-gateway-for-woocommerce' ), wp_kses_post( wpautop( $content ) ) );
	}

	/**
	 * Adds a retention selector under WooCommerce > Settings > Accounts & Privacy.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public function retention_setting( $settings ) {
		$insert = array(
			'title'       => __( 'Retain CardPointe payment data', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'desc_tip'    => __( 'Vault references and receipts on orders older than this are removed when a customer requests erasure.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'id'          => self::RETENTION_OPTION,
			'type'        => 'relative_date_selector',
			'placeholder' => __( 'N/A', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'default'     => '',
			'autoload'    => false,
		);
		$index = count( $settings ) - 1;
		array_splice( $settings, max( 0, $index ), 0, array( $insert ) );
		return $settings;
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['paradox-cardpointe'] = array(
			'exporter_friendly_name' => __( 'CardPointe payment data', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['paradox-cardpointe'] = array(
			'eraser_friendly_name' => __( 'CardPointe payment data', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Orders paid with this plugin for an email address.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{0: \WC_Order[], 1: bool} Orders for this page and whether paging is complete.
	 */
	private function orders( string $email, int $page ): array {
		$orders = array();
		$done   = true;
		$user   = get_user_by( 'email', $email );

		foreach ( Plugin::gateway_ids() as $gateway_id ) {
			$args = array(
				'payment_method' => $gateway_id,
				'limit'          => 10,
				'page'           => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			);
			if ( $user ) {
				$args['customer_id'] = $user->ID;
			} else {
				$args['billing_email'] = $email;
			}
			$batch = wc_get_orders( $args );
			if ( is_array( $batch ) ) {
				$orders = array_merge( $orders, $batch );
				if ( count( $batch ) >= 10 ) {
					$done = false;
				}
			}
		}

		return array( $orders, $done );
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$export = array();
		list( $orders, $done ) = $this->orders( $email, (int) $page );

		foreach ( $orders as $order ) {
			$stored = OrderMeta::stored_payment_method( $order );
			$data   = array(
				array( 'name' => __( 'Order', 'paradox-cardpointe-gateway-for-woocommerce' ), 'value' => $order->get_order_number() ),
				array( 'name' => __( 'CardPointe reference', 'paradox-cardpointe-gateway-for-woocommerce' ), 'value' => OrderMeta::retref( $order ) ),
				array( 'name' => __( 'Payment method', 'paradox-cardpointe-gateway-for-woocommerce' ), 'value' => trim( $stored['type'] . ' ' . $stored['brand'] . ' ****' . $stored['last4'] ) ),
				array( 'name' => __( 'Vault profile', 'paradox-cardpointe-gateway-for-woocommerce' ), 'value' => '' !== $stored['profile_id'] ? $stored['profile_id'] . '/' . $stored['acct_id'] : '' ),
			);
			$export[] = array(
				'group_id'    => 'paradox_cardpointe_orders',
				'group_label' => __( 'CardPointe payments', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'item_id'     => 'order-' . $order->get_id(),
				'data'        => $data,
			);
		}

		if ( 1 === (int) $page ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$data = array();
				foreach ( array( true, false ) as $sandbox ) {
					$credentials = Credentials::from_settings( Credentials::settings(), $sandbox );
					$profile_id  = ProfileService::get_user_profile_id( $user->ID, $credentials );
					if ( '' !== $profile_id ) {
						$data[] = array(
							'name'  => sprintf( /* translators: %s: environment */ __( 'CardPointe profile (%s)', 'paradox-cardpointe-gateway-for-woocommerce' ), $credentials->environment() ),
							'value' => $profile_id,
						);
					}
				}
				if ( $data ) {
					$export[] = array(
						'group_id'    => 'paradox_cardpointe_profile',
						'group_label' => __( 'CardPointe vault', 'paradox-cardpointe-gateway-for-woocommerce' ),
						'item_id'     => 'user-' . $user->ID,
						'data'        => $data,
					);
				}
			}
		}

		return array(
			'data' => $export,
			'done' => $done,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$removed  = false;
		$retained = false;
		$messages = array();
		list( $orders, $done ) = $this->orders( $email, (int) $page );

		foreach ( $orders as $order ) {
			if ( ! $this->retention_expired( $order ) ) {
				$retained = true;
				continue;
			}
			foreach ( array( OrderMeta::PROFILE_ID, OrderMeta::ACCT_ID, OrderMeta::TOKEN, OrderMeta::TOKEN_EXPIRY, OrderMeta::TOKEN_ID, OrderMeta::RECEIPT, OrderMeta::ACH_CONSENT ) as $key ) {
				OrderMeta::delete( $order, $key );
			}
			$order->save();
			$removed = true;
		}

		if ( 1 === (int) $page ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				foreach ( array( true, false ) as $sandbox ) {
					$credentials = Credentials::from_settings( Credentials::settings(), $sandbox );
					if ( '' !== ProfileService::get_user_profile_id( $user->ID, $credentials ) ) {
						ProfileService::forget_user_profile( $user->ID, $credentials );
						$removed = true;
					}
				}
			}
		}

		if ( $retained ) {
			$messages[] = __( 'Some CardPointe payment references were retained because the orders are within the retention period.', 'paradox-cardpointe-gateway-for-woocommerce' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Whether the retention window for an order has passed.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function retention_expired( \WC_Order $order ): bool {
		$retention = get_option( self::RETENTION_OPTION, array() );
		if ( empty( $retention['number'] ) || empty( $retention['unit'] ) ) {
			return true;
		}
		$created = $order->get_date_created();
		if ( ! $created ) {
			return true;
		}
		$number = (int) $retention['number'];
		switch ( $retention['unit'] ) {
			case 'days':
				$seconds = $number * DAY_IN_SECONDS;
				break;
			case 'weeks':
				$seconds = $number * WEEK_IN_SECONDS;
				break;
			case 'months':
				$seconds = $number * MONTH_IN_SECONDS;
				break;
			default:
				$seconds = $number * YEAR_IN_SECONDS;
		}
		return ( $created->getTimestamp() + $seconds ) < time();
	}
}
