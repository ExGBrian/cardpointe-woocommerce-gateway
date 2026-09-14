<?php
/**
 * Settings field definitions.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Settings;

use ParadoxSolutions\CardPointe\ApplePay\ApplePay;
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
				'title'   => __( 'Enable/Disable', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Enable CardPointe credit card payments', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method name shown to customers at checkout.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => __( 'Credit Card', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Short text shown under the payment method name at checkout.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => __( 'Pay securely with your credit or debit card.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);

		$fields += self::account_fields();

		$fields += array(
			'transactions_section'     => array(
				'title'       => __( 'Transactions', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'title',
				'description' => '',
			),
			'transaction_type'         => array(
				'title'       => __( 'Transaction type', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( '"Charge" captures funds immediately. "Authorize only" places a hold; capture later from the order screen or by changing the order status to Processing or Completed. Authorizations typically expire after 7 days.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'charge',
				'options'     => array(
					'charge'    => __( 'Charge (authorize and capture)', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'authorize' => __( 'Authorize only', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
			'capture_on_status_change' => array(
				'title'       => __( 'Capture on status change', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Capture authorized orders when their status changes to Processing or Completed, and void them when changed to Cancelled', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
			'accepted_card_types'      => array(
				'title'       => __( 'Accepted card types', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Cards of other brands are rejected before any charge is attempted.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => array( 'visa', 'mastercard', 'amex', 'discover' ),
				'options'     => CardTypes::options(),
				'desc_tip'    => true,
			),
			'bin_enforcement'          => array(
				'title'       => __( 'Card brand detection', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Look up the card brand with the CardPointe BIN service before charging (recommended)', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'When disabled, the brand is inferred from the token prefix only.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'saved_cards'              => array(
				'title'       => __( 'Saved cards', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Allow customers to save cards for faster checkout', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Card data is stored in the CardSecure vault as a CardPointe profile, never on this site.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'level2_data'              => array(
				'title'       => __( 'Level 2 data', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Send tax amount and invoice number with each transaction (can lower interchange for corporate cards)', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'default'     => 'yes',
			),
		);

		$fields += self::apple_pay_fields();
		$fields += self::receipt_fields();

		$fields += array(
			'advanced_section'         => array(
				'title'       => __( 'Advanced', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'title',
				'description' => '',
			),
			'iframe_css'               => array(
				'title'       => __( 'Card form CSS', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'min-height:160px;font-family:monospace;',
				'description' => __( 'CSS applied inside the hosted card form. Element IDs: #ccnumfield, #ccexpiryfieldmonth, #ccexpiryfieldyear, #cccvvfield, #cccardlabel, #ccexpirylabel, #cccvvlabel. CardPointe only accepts a limited subset. If the form loads with serif labels and unstyled inputs the whole stylesheet was rejected, so avoid quoted font names such as "Segoe UI", vendor tokens starting with a hyphen, comma-separated selector groups and shorthands like box-shadow. CardPointe also ignores box-sizing, so a percentage width excludes padding and borders: setting width:100% on a padded field overflows and pushes the expiry fields onto separate lines. Keep the account number below 100% and give the short fields fixed pixel widths.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => self::default_iframe_css( 'card' ),
			),
			'remove_data_on_uninstall' => array(
				'title'       => __( 'Uninstall', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Remove plugin settings and saved payment methods when the plugin is deleted', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'Order data is never removed.', 'paradox-cardpointe-gateway-for-woocommerce' ),
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
				'title'   => __( 'Enable/Disable', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Enable CardPointe eCheck (ACH) payments', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'title'               => array(
				'title'    => __( 'Title', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'     => 'safe_text',
				'default'  => __( 'Bank Account (eCheck)', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'desc_tip' => true,
			),
			'description'         => array(
				'title'    => __( 'Description', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'     => 'textarea',
				'default'  => __( 'Pay directly from your checking or savings account.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'desc_tip' => true,
			),
			'credentials_notice'  => array(
				'title'       => __( 'CardPointe account', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'paradox_notice',
				'description' => sprintf(
					/* translators: %s: settings URL */
					__( 'API credentials, sandbox mode and logging are configured on the <a href="%s">Credit Card gateway settings page</a>. eCheck uses the same account and requires ACH to be enabled on your merchant ID.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					esc_url( Plugin::settings_url( Plugin::CARD_GATEWAY_ID ) )
				),
			),
			'account_types'       => array(
				'title'   => __( 'Account types', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'default' => array( 'ECHK', 'ESAV' ),
				'options' => array(
					'ECHK' => __( 'Checking', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'ESAV' => __( 'Savings', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
			'approved_status'     => array(
				'title'       => __( 'Order status after acceptance', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'ACH payments are accepted immediately but can be returned days later. Choose On hold if you prefer to wait for funds before fulfilling.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'processing',
				'options'     => array(
					'processing' => __( 'Processing (payment complete)', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'on-hold'    => __( 'On hold (wait for funds to clear)', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
			'sec_mode'            => array(
				'title'       => __( 'ACH processor', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Controls which SEC code field is sent. Fiserv ACH uses achEntryCode; ProfitStars uses ecomind. "Both" is safe for most accounts.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'both',
				'options'     => array(
					'both'        => __( 'Send both fields (default)', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'fiserv'      => __( 'Fiserv ACH (achEntryCode only)', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'profitstars' => __( 'ProfitStars (ecomind only)', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
			'consent_text'        => array(
				'title'       => __( 'Authorization text', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'min-height:90px;',
				'description' => __( 'Shown with a required checkbox at checkout. Placeholders: {amount}, {company}, {site}.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => __( 'I authorize {company} to electronically debit my bank account for {amount}, and, if necessary, to credit my account to correct erroneous debits.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
			'saved_accounts'      => array(
				'title'   => __( 'Saved bank accounts', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Allow customers to save bank accounts for faster checkout', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);

		$fields += self::receipt_fields();

		$fields += array(
			'advanced_section' => array(
				'title' => __( 'Advanced', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'  => 'title',
			),
			'iframe_css'       => array(
				'title'       => __( 'Bank account form CSS', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'min-height:120px;font-family:monospace;',
				'description' => __( 'CSS applied inside the hosted bank account form. Element ID: #ccnumfield.', 'paradox-cardpointe-gateway-for-woocommerce' ),
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
				'title'       => __( 'CardPointe account', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Credentials are shared by the Credit Card and eCheck payment methods. Keep separate production and sandbox sets so you can switch with one checkbox.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
			'sandbox_mode'    => array(
				'title'       => __( 'Sandbox mode', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'       => __( 'Enable sandbox (UAT) mode and use the sandbox credentials', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'In sandbox mode no real money moves. Use test card 4111 1111 1111 1111 with any future expiry and CVV.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'yes',
			),
			'test_connection' => array(
				'title' => __( 'Connection', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'  => 'paradox_test_connection',
			),
		);

		foreach ( array( 'production', 'sandbox' ) as $env ) {
			$label = 'production' === $env ? __( 'Production', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Sandbox', 'paradox-cardpointe-gateway-for-woocommerce' );

			$fields[ $env . '_site' ] = array(
				/* translators: %s: environment label */
				'title'       => sprintf( __( '%s site name', 'paradox-cardpointe-gateway-for-woocommerce' ), $label ),
				'type'        => 'text',
				'description' => 'production' === $env
					? __( 'The subdomain of your CardPointe API URL, e.g. "fts" for https://fts.cardconnect.com.', 'paradox-cardpointe-gateway-for-woocommerce' )
					: __( 'Usually "fts"; resolves to https://fts-uat.cardconnect.com.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => 'fts',
				'desc_tip'    => true,
				'class'       => 'paradox-cardpointe-env-' . $env,
			);
			$fields[ $env . '_merchant_id' ] = array(
				/* translators: %s: environment label */
				'title'             => sprintf( __( '%s merchant ID', 'paradox-cardpointe-gateway-for-woocommerce' ), $label ),
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
				'title'             => sprintf( __( '%s API username', 'paradox-cardpointe-gateway-for-woocommerce' ), $label ),
				'type'              => 'text',
				'default'           => '',
				'class'             => 'paradox-cardpointe-env-' . $env,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			);
			$password = array(
				/* translators: %s: environment label */
				'title'             => sprintf( __( '%s API password', 'paradox-cardpointe-gateway-for-woocommerce' ), $label ),
				'type'              => 'password',
				'default'           => '',
				'class'             => 'paradox-cardpointe-env-' . $env,
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			);
			if ( Credentials::has_password_constant( $env ) ) {
				$password['description']                   = sprintf(
					/* translators: %s: constant name */
					__( 'Defined in wp-config.php via %s; the value stored here is ignored.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'PARADOX_CARDPOINTE_' . strtoupper( $env ) . '_API_PASSWORD'
				);
				$password['custom_attributes']['disabled'] = 'disabled';
			}
			$fields[ $env . '_api_password' ] = $password;
		}

		$fields['logging'] = array(
			'title'       => __( 'Logging', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'label'       => __( 'Log API requests and responses to the WooCommerce log (card numbers, CVV and passwords are never written)', 'paradox-cardpointe-gateway-for-woocommerce' ),
			'type'        => 'checkbox',
			'description' => sprintf(
				/* translators: %s: log viewer URL */
				__( 'View the log under <a href="%s">WooCommerce &rarr; Status &rarr; Logs</a>.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				esc_url( Logger::log_viewer_url() )
			),
			'default'     => 'no',
		);

		return $fields;
	}

	/**
	 * Apple Pay section (card gateway only).
	 */
	private static function apple_pay_fields(): array {
		if ( ! ApplePay::is_enabled() ) {
			$status = __( 'Apple Pay is switched off.', 'paradox-cardpointe-gateway-for-woocommerce' );
		} else {
			$problems = ApplePay::configuration_problems();
			$status   = empty( $problems )
				? __( 'Configured. The button is offered to shoppers using Safari on a device with Apple Pay set up, on HTTPS checkout and order-pay pages, for orders that do not need a saved payment method.', 'paradox-cardpointe-gateway-for-woocommerce' )
				: __( 'Not yet usable:', 'paradox-cardpointe-gateway-for-woocommerce' ) . ' ' . implode( ' ', $problems );
		}

		$fields = array(
			'apple_pay_section'            => array(
				'title'       => __( 'Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: verification file URL */
					__( 'Apple Pay on the web through CardSecure. Setup: (1) ask Fiserv Integration Delivery (integrationdelivery@fiserv.com) for a Payment Processing Certificate CSR, allow up to 5 business days, create the certificate under your Apple merchant ID from that CSR, and send the resulting .cer file back to your representative so CardSecure can decrypt Apple Pay tokens. (2) Create a Merchant Identity Certificate under the same merchant ID from a CSR you generate yourself (RSA 2048), convert it and its private key to PEM, and store both outside the web root. (3) Register this domain under the merchant ID, paste Apple\'s verification file below, and let Apple verify %s. Apple Pay always requires HTTPS, in sandbox mode too.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'<code>' . esc_html( ApplePay::association_url() ) . '</code>'
				),
			),
			'apple_pay_status'             => array(
				'title'       => __( 'Status', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'paradox_notice',
				'description' => esc_html( $status ),
			),
			'apple_pay_enabled'            => array(
				'title'   => __( 'Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Offer Apple Pay in the credit card payment box on the checkout and order-pay pages', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'apple_pay_merchant_id'        => array(
				'title'             => __( 'Apple merchant identifier', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'From the Apple Developer portal, for example merchant.com.example.store.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'apple_pay_display_name'       => array(
				'title'       => __( 'Name on the payment sheet', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Up to 64 characters. Defaults to the site title.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => '',
				'placeholder' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'desc_tip'    => true,
			),
		);

		$paths = array(
			'cert_path'      => array(
				'title'       => __( 'Merchant identity certificate', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Absolute server path to the certificate in PEM format. Keep it outside the web root.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
			'key_path'       => array(
				'title'       => __( 'Private key', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Absolute server path to the private key in PEM format. Leave empty if the certificate file also contains the key.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
			'key_passphrase' => array(
				'title'       => __( 'Private key passphrase', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Only if the private key is encrypted.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
		);
		foreach ( $paths as $key => $field ) {
			$field['default']           = '';
			$field['custom_attributes'] = array( 'autocomplete' => 'password' === $field['type'] ? 'new-password' : 'off' );
			$constant                   = 'PARADOX_CARDPOINTE_APPLE_PAY_' . strtoupper( $key );
			if ( ApplePay::has_constant( $key ) ) {
				$field['description']                   = sprintf(
					/* translators: %s: constant name */
					__( 'Defined in wp-config.php via %s; the value stored here is ignored.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					$constant
				);
				$field['custom_attributes']['disabled'] = 'disabled';
			} else {
				$field['description'] .= ' ' . sprintf(
					/* translators: %s: constant name */
					__( 'Can also be set in wp-config.php with the %s constant.', 'paradox-cardpointe-gateway-for-woocommerce' ),
					$constant
				);
			}
			$fields[ 'apple_pay_' . $key ] = $field;
		}

		$fields += array(
			'apple_pay_domain_association' => array(
				'title'       => __( 'Domain verification file', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'css'         => 'min-height:90px;font-family:monospace;',
				'description' => __( 'Contents of the apple-developer-merchantid-domain-association.txt file from the Apple Developer portal. It is served at /.well-known/apple-developer-merchantid-domain-association.txt. If your host does not pass that path to WordPress, upload the file there yourself and leave this empty.', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'default'     => '',
			),
			'apple_pay_button_style'       => array(
				'title'   => __( 'Button style', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'black',
				'options' => array(
					'black'         => __( 'Black', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'white'         => __( 'White', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'white-outline' => __( 'White with outline', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
			'apple_pay_button_type'        => array(
				'title'   => __( 'Button label', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'plain',
				'options' => array(
					'plain'     => __( 'Apple Pay logo only', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'buy'       => __( 'Buy with Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'pay'       => __( 'Pay with Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'check-out' => __( 'Check out with Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
					'order'     => __( 'Order with Apple Pay', 'paradox-cardpointe-gateway-for-woocommerce' ),
				),
			),
		);

		return $fields;
	}

	/**
	 * Receipt fields shared by both gateways.
	 */
	private static function receipt_fields(): array {
		return array(
			'receipt_section'    => array(
				'title'       => __( 'Gateway receipts', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'CardPointe can return receipt details (merchant DBA, address, authorization code) with each transaction.', 'paradox-cardpointe-gateway-for-woocommerce' ),
			),
			'receipt_enabled'    => array(
				'title'   => __( 'Request receipts', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Request gateway receipt data and store it with the order', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
			'receipt_on_thankyou' => array(
				'title'   => __( 'Show on order pages', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Display the receipt on the order received page and in My Account order details', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			'receipt_in_emails'  => array(
				'title'   => __( 'Include in emails', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'label'   => __( 'Append the receipt to customer order emails', 'paradox-cardpointe-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'no',
			),
		);
	}

	/**
	 * Default CSS injected into the hosted iframe.
	 *
	 * CardPointe validates this stylesheet strictly, and two separate failure modes bite here.
	 * It silently discards the whole sheet if it dislikes any part, so avoid quoted font names,
	 * leading-hyphen vendor tokens, comma-separated selector groups and multi-value shorthands.
	 * It also drops `box-sizing` on its own, which means a percentage width does NOT include
	 * padding and borders: `width:100%` on a padded input overflows its container and pushes
	 * the expiry month and year onto separate lines. Hence 80% for the account number, and
	 * fixed pixel widths for the short fields. This combination is verified against the
	 * live tokenizer; re-test in the sandbox before changing any width here.
	 *
	 * @param string $type card|echeck.
	 */
	public static function default_iframe_css( string $type ): string {
		$base = 'body{font-family:system-ui,sans-serif;color:#2c3338}'
			. 'label{font-size:15px;font-weight:600;margin:10px 0 4px}'
			. 'input{font-family:system-ui,sans-serif;font-size:17px;padding:6px 8px;margin:0 0 14px;border:1px solid #8c8f94;border-radius:4px;color:#2c3338}'
			. 'input:focus{border-color:#2271b1}'
			. '.error{border-color:#d63638}'
			. '#ccnumfield{width:80%}';

		if ( 'echeck' === $type ) {
			return $base;
		}

		return $base
			. '#ccexpiryfieldmonth{width:100px}'
			. '#ccexpiryfieldyear{width:100px}'
			. '#cccvvfield{width:100px}';
	}
}
