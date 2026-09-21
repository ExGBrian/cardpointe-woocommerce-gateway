<?php
/**
 * Credit card fields (hosted iframe mount point).
 *
 * Override by copying to yourtheme/paradox-cardpointe-gateway-for-woocommerce/checkout/card-fields.php.
 *
 * @var \ParadoxSolutions\CardPointe\Gateway\CardGateway $gateway
 * @var string   $field_prefix
 * @var string   $tokenizer_url
 * @var int      $iframe_height
 * @var string[] $card_types
 * @var array|null $apple_pay  Apple Pay button data (ApplePay::button_context()), or null.
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'ABSPATH' ) || exit;
?>
<fieldset id="wc-<?php echo esc_attr( $field_prefix ); ?>-cc-form"
	class="wc-credit-card-form wc-payment-form wc-<?php echo esc_attr( $field_prefix ); ?>-payment-form paradox-cardpointe-form"
	data-gateway="<?php echo esc_attr( $field_prefix ); ?>"
	data-type="card">
	<?php do_action( 'woocommerce_credit_card_form_start', $field_prefix ); ?>

	<div class="paradox-cardpointe-errors woocommerce-error" role="alert" aria-live="assertive" hidden></div>

	<?php if ( ! empty( $apple_pay ) ) : ?>
		<?php // Hidden until apple-pay.js confirms the browser can use Apple Pay and fills the slot; the total is refreshed with the payment box. ?>
		<div class="paradox-cardpointe-apple-pay"
			data-gateway="<?php echo esc_attr( $field_prefix ); ?>"
			data-amount="<?php echo esc_attr( $apple_pay['amount'] ); ?>"
			data-currency="<?php echo esc_attr( $apple_pay['currency'] ); ?>"
			data-country="<?php echo esc_attr( $apple_pay['country'] ); ?>"
			data-label="<?php echo esc_attr( $apple_pay['label'] ); ?>"
			data-networks="<?php echo esc_attr( implode( ',', $apple_pay['networks'] ) ); ?>"
			hidden>
			<div class="paradox-cardpointe-apple-pay-slot"></div>
			<p class="paradox-cardpointe-apple-pay-divider"><span><?php esc_html_e( 'or enter your card details', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></span></p>
		</div>
	<?php endif; ?>

	<div class="paradox-cardpointe-frame-wrap" style="min-height:<?php echo (int) $iframe_height; ?>px;">
		<div class="paradox-cardpointe-frame"
			data-src="<?php echo esc_url( $tokenizer_url ); ?>"
			data-origin="<?php echo esc_attr( $gateway->tokenizer_origin() ); ?>"
			data-height="<?php echo (int) $iframe_height; ?>"
			data-title="<?php esc_attr_e( 'Secure card entry form', 'paradox-cardpointe-gateway-for-woocommerce' ); ?>"></div>
		<p class="paradox-cardpointe-loading"><?php esc_html_e( 'Loading secure payment form…', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
	</div>

	<p class="paradox-cardpointe-status" aria-live="polite"></p>

	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_token" class="paradox-cardpointe-token" value="" />
	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_expiry" class="paradox-cardpointe-expiry" value="" />
	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_brand_hint" class="paradox-cardpointe-brand" value="" />

	<?php do_action( 'woocommerce_credit_card_form_end', $field_prefix ); ?>
	<div class="clear"></div>
</fieldset>
