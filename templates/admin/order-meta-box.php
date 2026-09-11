<?php
/**
 * CardPointe order meta box.
 *
 * @var \WC_Order $order
 * @var array     $info  Summary built by Admin\OrderActions::summary().
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="paradox-cardpointe-meta-box" data-order-id="<?php echo (int) $order->get_id(); ?>">
	<p>
		<span class="paradox-cardpointe-badge <?php echo 'sandbox' === $info['environment'] ? 'paradox-cardpointe-badge-sandbox' : 'paradox-cardpointe-badge-live'; ?>">
			<?php echo esc_html( 'sandbox' === $info['environment'] ? __( 'Sandbox', 'paradox-cardpointe-gateway-for-woocommerce' ) : __( 'Production', 'paradox-cardpointe-gateway-for-woocommerce' ) ); ?>
		</span>
		<strong class="paradox-cardpointe-state"><?php echo esc_html( $info['state_label'] ); ?></strong>
	</p>

	<table class="paradox-cardpointe-details">
		<?php foreach ( $info['rows'] as $label => $value ) : ?>
			<?php if ( '' === (string) $value ) { continue; } ?>
			<tr>
				<th scope="row"><?php echo esc_html( $label ); ?></th>
				<td><?php echo esc_html( $value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>

	<?php if ( ! empty( $info['refunds'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Voids / refunds', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></strong></p>
		<ul class="paradox-cardpointe-refunds">
			<?php foreach ( $info['refunds'] as $record ) : ?>
				<li><?php echo esc_html( $record ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $info['pending'] ) : ?>
		<p class="paradox-cardpointe-warning"><?php esc_html_e( 'A payment attempt did not complete. Use "Refresh status" to check CardPointe for an approved transaction.', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></p>
	<?php endif; ?>

	<?php if ( $info['expires_note'] ) : ?>
		<p class="paradox-cardpointe-warning"><?php echo esc_html( $info['expires_note'] ); ?></p>
	<?php endif; ?>

	<div class="paradox-cardpointe-actions">
		<?php if ( $info['can_capture'] ) : ?>
			<p>
				<label for="paradox-cardpointe-capture-amount"><?php esc_html_e( 'Capture amount', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></label>
				<input type="text" id="paradox-cardpointe-capture-amount" class="wc_input_price" value="<?php echo esc_attr( $info['capture_amount'] ); ?>" />
				<button type="button" class="button button-primary paradox-cardpointe-action" data-action="capture"><?php esc_html_e( 'Capture', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></button>
			</p>
		<?php endif; ?>
		<?php if ( $info['can_void'] ) : ?>
			<p><button type="button" class="button paradox-cardpointe-action" data-action="void"><?php esc_html_e( 'Void', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></button></p>
		<?php endif; ?>
		<?php if ( $info['has_retref'] || $info['pending'] ) : ?>
			<p><button type="button" class="button-link paradox-cardpointe-action" data-action="inquire"><?php esc_html_e( 'Refresh status', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></button></p>
		<?php endif; ?>
		<div class="paradox-cardpointe-action-result" aria-live="polite"></div>
	</div>
</div>
