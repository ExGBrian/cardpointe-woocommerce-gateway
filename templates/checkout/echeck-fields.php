<?php
/**
 * eCheck (ACH) fields (hosted iframe mount point).
 *
 * Override by copying to yourtheme/paradox-cardpointe-gateway/checkout/echeck-fields.php.
 *
 * @var \ParadoxSolutions\CardPointe\Gateway\EcheckGateway $gateway
 * @var string   $field_prefix
 * @var string   $tokenizer_url
 * @var int      $iframe_height
 * @var string[] $account_types
 * @var string   $consent_text
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'ABSPATH' ) || exit;

$labels = array(
	'ECHK' => __( 'Checking', 'paradox-cardpointe-gateway' ),
	'ESAV' => __( 'Savings', 'paradox-cardpointe-gateway' ),
);
?>
<fieldset id="wc-<?php echo esc_attr( $field_prefix ); ?>-echeck-form"
	class="wc-payment-form wc-<?php echo esc_attr( $field_prefix ); ?>-payment-form paradox-cardpointe-form paradox-cardpointe-echeck-form"
	data-gateway="<?php echo esc_attr( $field_prefix ); ?>"
	data-type="echeck">

	<div class="paradox-cardpointe-errors woocommerce-error" role="alert" aria-live="assertive" hidden></div>

	<p class="form-row form-row-wide paradox-cardpointe-accttype">
		<span class="paradox-cardpointe-label"><?php esc_html_e( 'Account type', 'paradox-cardpointe-gateway' ); ?> <span class="required">*</span></span>
		<?php foreach ( $account_types as $index => $type ) : ?>
			<label class="paradox-cardpointe-radio">
				<input type="radio" name="<?php echo esc_attr( $field_prefix ); ?>_accttype" value="<?php echo esc_attr( $type ); ?>" <?php checked( 0 === $index ); ?> />
				<?php echo esc_html( $labels[ $type ] ?? $type ); ?>
			</label>
		<?php endforeach; ?>
	</p>

	<p class="form-row form-row-wide paradox-cardpointe-help">
		<span class="paradox-cardpointe-label"><?php esc_html_e( 'Routing number / Account number', 'paradox-cardpointe-gateway' ); ?> <span class="required">*</span></span>
		<small><?php esc_html_e( 'Type your routing number, a slash, then your account number, e.g. 123456789/000123456.', 'paradox-cardpointe-gateway' ); ?></small>
	</p>

	<div class="paradox-cardpointe-frame-wrap" style="min-height:<?php echo (int) $iframe_height; ?>px;">
		<div class="paradox-cardpointe-frame"
			data-src="<?php echo esc_url( $tokenizer_url ); ?>"
			data-origin="<?php echo esc_attr( $gateway->tokenizer_origin() ); ?>"
			data-height="<?php echo (int) $iframe_height; ?>"
			data-title="<?php esc_attr_e( 'Secure bank account entry form', 'paradox-cardpointe-gateway' ); ?>"></div>
		<p class="paradox-cardpointe-loading"><?php esc_html_e( 'Loading secure payment form…', 'paradox-cardpointe-gateway' ); ?></p>
	</div>

	<p class="paradox-cardpointe-status" aria-live="polite"></p>

	<p class="form-row form-row-wide paradox-cardpointe-consent">
		<label class="checkbox">
			<input type="checkbox" name="<?php echo esc_attr( $field_prefix ); ?>_consent" value="1" />
			<?php echo esc_html( $consent_text ); ?> <span class="required">*</span>
		</label>
	</p>

	<input type="hidden" name="<?php echo esc_attr( $field_prefix ); ?>_token" class="paradox-cardpointe-token" value="" />

	<div class="clear"></div>
</fieldset>
