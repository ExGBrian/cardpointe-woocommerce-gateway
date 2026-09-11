<?php
/**
 * Gateway receipt.
 *
 * Override by copying to yourtheme/paradox-cardpointe-gateway-for-woocommerce/receipt.php.
 *
 * @var \WC_Order $order
 * @var array     $receipt Raw receipt from CardPointe.
 * @var array     $rows    key => [label, value].
 * @var string    $header
 * @var string    $footer
 * @var bool      $plain
 *
 * @package ParadoxSolutions\CardPointe
 */

defined( 'ABSPATH' ) || exit;

if ( $plain ) {
	echo "\n" . esc_html( __( 'PAYMENT RECEIPT', 'paradox-cardpointe-gateway-for-woocommerce' ) ) . "\n";
	if ( '' !== $header ) {
		echo esc_html( $header ) . "\n";
	}
	foreach ( $rows as $row ) {
		echo esc_html( $row[0] ) . ': ' . esc_html( $row[1] ) . "\n";
	}
	if ( '' !== $footer ) {
		echo esc_html( $footer ) . "\n";
	}
	return;
}
?>
<section class="paradox-cardpointe-receipt woocommerce-order-details">
	<h2 class="woocommerce-column__title"><?php esc_html_e( 'Payment receipt', 'paradox-cardpointe-gateway-for-woocommerce' ); ?></h2>
	<?php if ( '' !== $header ) : ?>
		<p class="paradox-cardpointe-receipt-header"><?php echo esc_html( $header ); ?></p>
	<?php endif; ?>
	<table class="woocommerce-table shop_table paradox-cardpointe-receipt-table">
		<tbody>
		<?php foreach ( $rows as $key => $row ) : ?>
			<tr class="paradox-cardpointe-receipt-<?php echo esc_attr( $key ); ?>">
				<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
				<td><?php echo esc_html( $row[1] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( '' !== $footer ) : ?>
		<p class="paradox-cardpointe-receipt-footer"><?php echo esc_html( $footer ); ?></p>
	<?php endif; ?>
</section>
