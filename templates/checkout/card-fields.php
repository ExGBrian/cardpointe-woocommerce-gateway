<?php
/**
 * Credit card fields (hosted iframe mount point).
 *
 * Override by copying to yourtheme/paradox-cardpointe-gateway/checkout/card-fields.php.
 *
 * @var \ParadoxSolutions\CardPointe\Gateway\CardGateway $gateway
 * @var string   $field_prefix
 * @var string   $tokenizer_url
 * @var int      $iframe_height
 * @var string[] $card_types
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

	<div class="paradox-cardpointe-frame-wrap" style="min-height:<?php echo (int) $iframe_height; ?>px;">
		<div class="paradox-cardpointe-frame"
			data-src="<?php echo esc_url( $tokenizer_url ); ?>"
			data-origin="<?php echo esc_attr( $gateway->tokenizer_origin() ); ?>"
			data-height="<?php echo (int) $iframe_height; ?>"
			data-title="<?php esc_attr_e( 'Secure card entry form', 'paradox-cardpointe-gateway' ); ?>"></div>
		<p class="paradox-cardpointe-loading"><?php esc_html_e( 'Loading secure payment form…', 'paradox-cardpointe-gateway' ); ?></p>
	</div>

	<p class="paradox-cardpointe-status" aria-live="polite"></p>

	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_token" class="paradox-cardpointe-token" value="" />
	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_expiry" class="paradox-cardpointe-expiry" value="" />
	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_brand_hint" class="paradox-cardpointe-brand" value="" />

	<?php do_action( 'woocommerce_credit_card_form_end', $field_prefix ); ?>
	<div class="clear"></div>
</fieldset>
