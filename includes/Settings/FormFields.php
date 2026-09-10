<?php
/**
 * Settings field definitions.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Settings;

use ParadoxSolutions\CardPointe\Gateway\CardTypes;
use ParadoxSolutions\CardPointe\Logging\Logger;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooCommerce Settings API field arrays for both gateways.
 */
final class FormFields {

	/**
	 * Card gateway fields. Also hosts the shared account/credential section.
	 */
	public static function card(): array {
		$fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Enable CardPointe credit card payments', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'paradox-cardpointe-gateway' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'paradox-cardpointe-gateway' ),
				'default'     => __( 'Credit Card', 'paradox-cardpointe-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'paradox-cardpointe-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Short text shown under the payment method name at checkout.', 'paradox-cardpointe-gateway' ),
				'default'     => __( 'Pay securely with your credit or debit card.', 'paradox-cardpointe-gateway' ),
				'desc_tip'    => true,
			),
		);

		$fields += self::account_fields();

		$fields += array(
			'transactions_section'     => array(
				'title'       => __( 'Transactions', 'paradox-cardpointe-gateway' ),
				'type'        => 'title',
				'description' => '',
			),
			'transaction_type'         => array(
				'title'       => __( 'Transaction type', 'paradox-cardpointe-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( '"Charge" captures funds immediately. "Authorize only" places a hold; capture later from the order screen or by changing the order status to Processing or Completed. Authorizations typically expire after 7 days.', 'paradox-cardpointe-gateway' ),
				'default'     => 'charge',
				'options'     => array(
					'charge'    => __( 'Charge (authorize and capture)', 'paradox-cardpointe-gateway' ),
					'authorize' => __( 'Authorize only', 'paradox-cardpointe-gateway' ),
				),
			),
			'capture_on_status_change' => array(
				'title'       => __( 'Capture on status change', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Capture authorized orders when their status changes to Processing or Completed, and void them when changed to Cancelled', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
			'accepted_card_types'      => array(
				'title'       => __( 'Accepted card types', 'paradox-cardpointe-gateway' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Cards of other brands are rejected before any charge is attempted.', 'paradox-cardpointe-gateway' ),
				'default'     => array( 'visa', 'mastercard', 'amex', 'discover' ),
				'options'     => CardTypes::options(),
				'desc_tip'    => true,
			),
			'bin_enforcement'          => array(
				'title'       => __( 'Card brand detection', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Look up the card brand with the CardPointe BIN service before charging (recommended)', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'When disabled, the brand is inferred from the token prefix only.', 'paradox-cardpointe-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'saved_cards'              => array(
				'title'       => __( 'Saved cards', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Allow customers to save cards for faster checkout', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Card data is stored in the CardSecure vault as a CardPointe profile, never on this site.', 'paradox-cardpointe-gateway' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'level2_data'              => array(
				'title'       => __( 'Level 2 data', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Send tax amount and invoice number with each transaction (can lower interchange for corporate cards)', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
		);

		$fields += self::receipt_fields();

		$fields += array(
			'advanced_section'         => array(
				'title'       => __( 'Advanced', 'paradox-cardpointe-gateway' ),
				'type'        => 'title',
				'description' => '',
			),
			'iframe_css'               => array(
				'title'       => __( 'Card form CSS', 'paradox-cardpointe-gateway' ),
				'type'        => 'textarea',
				'css'         => 'min-height:160px;font-family:monospace;',
				'description' => __( 'CSS applied inside the hosted card form. Element IDs: #ccnumfield, #ccexpiryfieldmonth, #ccexpiryfieldyear, #cccvvfield, #cccardlabel, #ccexpirylabel, #cccvvlabel. CardPointe validates this strictly and drops the whole stylesheet if any part is rejected, so avoid quoted font names such as "Segoe UI", vendor tokens starting with a hyphen, comma-separated selector groups and shorthands like box-shadow. If the form loads with serif labels and unstyled inputs, your CSS was rejected.', 'paradox-cardpointe-gateway' ),
				'default'     => self::default_iframe_css( 'card' ),
			),
			'remove_data_on_uninstall' => array(
				'title'       => __( 'Uninstall', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Remove plugin settings and saved payment methods when the plugin is deleted', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Order data is never removed.', 'paradox-cardpointe-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
		);

		/**
		 * Filters the card gateway settings fields.
		 *
		 * @param array $fields Fields.
		 */
		return apply_filters( 'paradox_cardpointe_card_form_fields', $fields );
	}

	/**
	 * eCheck gateway fields.
	 */
	public static function echeck(): array {
		$fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Enable CardPointe eCheck (ACH) payments', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'               => array(
				'title'    => __( 'Title', 'paradox-cardpointe-gateway' ),
				'type'     => 'safe_text',
				'default'  => __( 'Bank Account (eCheck)', 'paradox-cardpointe-gateway' ),
				'desc_tip' => true,
			),
			'description'         => array(
				'title'    => __( 'Description', 'paradox-cardpointe-gateway' ),
				'type'     => 'textarea',
				'default'  => __( 'Pay directly from your checking or savings account.', 'paradox-cardpointe-gateway' ),
				'desc_tip' => true,
			),
			'credentials_notice'  => array(
				'title'       => __( 'CardPointe account', 'paradox-cardpointe-gateway' ),
				'type'        => 'paradox_notice',
				'description' => sprintf(
					/* translators: %s: settings URL */
					__( 'API credentials, sandbox mode and logging are configured on the <a href="%s">Credit Card gateway settings page</a>. eCheck uses the same account and requires ACH to be enabled on your merchant ID.', 'paradox-cardpointe-gateway' ),
					esc_url( Plugin::settings_url( Plugin::CARD_GATEWAY_ID ) )
				),
			),
			'account_types'       => array(
				'title'   => __( 'Account types', 'paradox-cardpointe-gateway' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'default' => array( 'ECHK', 'ESAV' ),
				'options' => array(
					'ECHK' => __( 'Checking', 'paradox-cardpointe-gateway' ),
					'ESAV' => __( 'Savings', 'paradox-cardpointe-gateway' ),
				),
			),
			'approved_status'     => array(
				'title'       => __( 'Order status after acceptance', 'paradox-cardpointe-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'ACH payments are accepted immediately but can be returned days later. Choose On hold if you prefer to wait for funds before fulfilling.', 'paradox-cardpointe-gateway' ),
				'default'     => 'processing',
				'options'     => array(
					'processing' => __( 'Processing (payment complete)', 'paradox-cardpointe-gateway' ),
					'on-hold'    => __( 'On hold (wait for funds to clear)', 'paradox-cardpointe-gateway' ),
				),
			),
			'sec_mode'            => array(
				'title'       => __( 'ACH processor', 'paradox-cardpointe-gateway' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Controls which SEC code field is sent. Fiserv ACH uses achEntryCode; ProfitStars uses ecomind. "Both" is safe for most accounts.', 'paradox-cardpointe-gateway' ),
				'default'     => 'both',
				'options'     => array(
					'both'        => __( 'Send both fields (default)', 'paradox-cardpointe-gateway' ),
					'fiserv'      => __( 'Fiserv ACH (achEntryCode only)', 'paradox-cardpointe-gateway' ),
					'profitstars' => __( 'ProfitStars (ecomind only)', 'paradox-cardpointe-gateway' ),
				),
			),
			'consent_text'        => array(
				'title'       => __( 'Authorization text', 'paradox-cardpointe-gateway' ),
				'type'        => 'textarea',
				'css'         => 'min-height:90px;',
				'description' => __( 'Shown with a required checkbox at checkout. Placeholders: {amount}, {company}, {site}.', 'paradox-cardpointe-gateway' ),
				'default'     => __( 'I authorize {company} to electronically debit my bank account for {amount}, and, if necessary, to credit my account to correct erroneous debits.', 'paradox-cardpointe-gateway' ),
			),
			'saved_accounts'      => array(
				'title'   => __( 'Saved bank accounts', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Allow customers to save bank accounts for faster checkout', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);

		$fields += self::receipt_fields();

		$fields += array(
			'advanced_section' => array(
				'title' => __( 'Advanced', 'paradox-cardpointe-gateway' ),
				'type'  => 'title',
			),
			'iframe_css'       => array(
				'title'       => __( 'Bank account form CSS', 'paradox-cardpointe-gateway' ),
				'type'        => 'textarea',
				'css'         => 'min-height:120px;font-family:monospace;',
				'description' => __( 'CSS applied inside the hosted bank account form. Element ID: #ccnumfield.', 'paradox-cardpointe-gateway' ),
				'default'     => self::default_iframe_css( 'echeck' ),
			),
		);

		/**
		 * Filters the eCheck gateway settings fields.
		 *
		 * @param array $fields Fields.
		 */
		return apply_filters( 'paradox_cardpointe_echeck_form_fields', $fields );
	}

	/**
	 * Shared account/credential fields (card gateway only).
	 */
	private static function account_fields(): array {
		$fields = array(
			'account_section' => array(
				'title'       => __( 'CardPointe account', 'paradox-cardpointe-gateway' ),
				'type'        => 'title',
				'description' => __( 'Credentials are shared by the Credit Card and eCheck payment methods. Keep separate production and sandbox sets so you can switch with one checkbox.', 'paradox-cardpointe-gateway' ),
			),
			'sandbox_mode'    => array(
				'title'       => __( 'Sandbox mode', 'paradox-cardpointe-gateway' ),
				'label'       => __( 'Enable sandbox (UAT) mode and use the sandbox credentials', 'paradox-cardpointe-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'In sandbox mode no real money moves. Use test card 4111 1111 1111 1111 with any future expiry and CVV.', 'paradox-cardpointe-gateway' ),
				'default'     => 'yes',
			),
			'test_connection' => array(
				'title' => __( 'Connection', 'paradox-cardpointe-gateway' ),
				'type'  => 'paradox_test_connection',
			),
		);

		foreach ( array( 'production', 'sandbox' ) as $env ) {
			$label = 'production' === $env ? __( 'Production', 'paradox-cardpointe-gateway' ) : __( 'Sandbox', 'paradox-cardpointe-gateway' );

			$fields[ $env . '_site' ] = array(
				/* translators: %s: environment label */
				'title'       => sprintf( __( '%s site name', 'paradox-cardpointe-gateway' ), $label ),
				'type'        => 'text',
				'description' => 'production' === $env
					? __( 'The subdomain of your CardPointe API URL, e.g. "fts" for https://fts.cardconnect.com.', 'paradox-cardpointe-gateway' )
					: __( 'Usually "fts"; resolves to https://fts-uat.cardconnect.com.', 'paradox-cardpointe-gateway' ),
				'default'     => 'fts',
				'desc_tip'    => true,
				'class'       => 'paradox-cardpointe-env-' . $env,
			);
			$fields[ $env . '_merchant_id' ] = array(
				/* translators: %s: environment label */
				'title'             => sprintf( __( '%s merchant ID', 'paradox-cardpointe-gateway' ), $label ),
				'type'              => 'text',
				'default'           => '',
				'class'             => 'paradox-cardpointe-env-' . $env,
				'custom_attributes' => array(
					'inputmode'    => 'numeric',
					'autocomplete' => 'off',
				),
			);
			$fields[ $env . '_api_username' ] = array(
				/* translators: %s: environment label */
				'title'             => sprintf( __( '%s API username', 'paradox-cardpointe-gateway' ), $label ),
				'type'              => 'text',
				'default'           => '',
				'class'             => 'paradox-cardpointe-env-' . $env,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			);
			$password = array(
				/* translators: %s: environment label */
				'title'             => sprintf( __( '%s API password', 'paradox-cardpointe-gateway' ), $label ),
				'type'              => 'password',
				'default'           => '',
				'class'             => 'paradox-cardpointe-env-' . $env,
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			);
			if ( Credentials::has_password_constant( $env ) ) {
				$password['description']                   = sprintf(
					/* translators: %s: constant name */
					__( 'Defined in wp-config.php via %s; the value stored here is ignored.', 'paradox-cardpointe-gateway' ),
					'PARADOX_CARDPOINTE_' . strtoupper( $env ) . '_API_PASSWORD'
				);
				$password['custom_attributes']['disabled'] = 'disabled';
			}
			$fields[ $env . '_api_password' ] = $password;
		}

		$fields['logging'] = array(
			'title'       => __( 'Logging', 'paradox-cardpointe-gateway' ),
			'label'       => __( 'Log API requests and responses to the WooCommerce log (card numbers, CVV and passwords are never written)', 'paradox-cardpointe-gateway' ),
			'type'        => 'checkbox',
			'description' => sprintf(
				/* translators: %s: log viewer URL */
				__( 'View the log under <a href="%s">WooCommerce &rarr; Status &rarr; Logs</a>.', 'paradox-cardpointe-gateway' ),
				esc_url( Logger::log_viewer_url() )
			),
			'default'     => 'no',
		);

		return $fields;
	}

	/**
	 * Receipt fields shared by both gateways.
	 */
	private static function receipt_fields(): array {
		return array(
			'receipt_section'    => array(
				'title'       => __( 'Gateway receipts', 'paradox-cardpointe-gateway' ),
				'type'        => 'title',
				'description' => __( 'CardPointe can return receipt details (merchant DBA, address, authorization code) with each transaction.', 'paradox-cardpointe-gateway' ),
			),
			'receipt_enabled'    => array(
				'title'   => __( 'Request receipts', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Request gateway receipt data and store it with the order', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'receipt_on_thankyou' => array(
				'title'   => __( 'Show on order pages', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Display the receipt on the order received page and in My Account order details', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'receipt_in_emails'  => array(
				'title'   => __( 'Include in emails', 'paradox-cardpointe-gateway' ),
				'label'   => __( 'Append the receipt to customer order emails', 'paradox-cardpointe-gateway' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
		);
	}

	/**
	 * Default CSS injected into the hosted iframe.
	 *
	 * CardPointe validates this stylesheet strictly and silently discards the whole
	 * thing when it dislikes any part of it, so keep to the conservative subset below:
	 * no quoted font names, no leading-hyphen vendor tokens, one selector per rule
	 * (no comma groups) and no multi-value shorthands such as box-shadow.
	 *
	 * @param string $type card|echeck.
	 */
	public static function default_iframe_css( string $type ): string {
		$base = 'body{margin:0;padding:0;font-family:system-ui,sans-serif;font-size:14px;color:#2c3338}'
			. 'label{display:block;font-size:14px;font-weight:600;margin:0 0 4px;line-height:1.4}'
			. 'input{width:100%;box-sizing:border-box;font-size:16px;line-height:1.4;padding:10px 12px;margin:0 0 12px;border:1px solid #8c8f94;border-radius:4px;background:#fff;color:#2c3338}'
			. 'select{width:100%;box-sizing:border-box;font-size:16px;line-height:1.4;padding:10px 12px;margin:0 0 12px;border:1px solid #8c8f94;border-radius:4px;background:#fff;color:#2c3338}'
			. 'input:focus{outline:none;border-color:#2271b1}'
			. 'select:focus{outline:none;border-color:#2271b1}'
			. '.error{border-color:#d63638}';

		if ( 'echeck' === $type ) {
			return $base;
		}

		return $base
			. '#ccexpiryfieldmonth{display:inline-block;width:46%}'
			. '#ccexpiryfieldyear{display:inline-block;width:46%}'
			. '#ccexpirymonth{display:inline-block;width:46%}'
			. '#ccexpiryyear{display:inline-block;width:46%}'
			. '#cccvvfield{width:46%}';
	}
}
