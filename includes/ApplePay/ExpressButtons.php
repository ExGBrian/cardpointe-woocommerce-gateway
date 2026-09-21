<?php
/**
 * Apple Pay express buttons on classic (shortcode) pages.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\ApplePay;

use ParadoxSolutions\CardPointe\Gateway\CardGateway;
use ParadoxSolutions\CardPointe\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the express button containers on the single product page, the cart and at
 * the top of the checkout, including CheckoutWC's express area.
 *
 * Only an empty, hidden slot is printed. apple-pay.js fills it once the browser says
 * it can use Apple Pay, so nothing appears for shoppers who cannot pay this way. The
 * Cart and Checkout blocks get their button through the express payment method
 * registered in blocks-apple-pay.js instead.
 */
final class ExpressButtons {

	/** @var bool The checkout hooks can both fire on one page; render once. */
	private $checkout_rendered = false;

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'product' ), 5 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'cart' ), 5 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'checkout' ), 5 );
		add_action( 'cfw_payment_request_buttons', array( $this, 'checkout_cfw' ), 5 );
	}

	/**
	 * Card gateway, when Apple Pay can be offered at all.
	 *
	 * @return CardGateway|null
	 */
	private function gateway() {
		if ( ! ApplePay::is_configured() || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateway = Plugin::gateway( Plugin::CARD_GATEWAY_ID );
		return $gateway instanceof CardGateway ? $gateway : null;
	}

	/**
	 * Single product page, under the add to cart form.
	 */
	public function product() {
		global $product;
		$gateway = $this->gateway();
		if ( ! $gateway || ! $product instanceof \WC_Product ) {
			return;
		}
		$context = ApplePay::express_context( $gateway, 'product', $product );
		if ( null !== $context ) {
			$this->render( $context, 'before' );
		}
	}

	/**
	 * Cart page, above "Proceed to checkout".
	 */
	public function cart() {
		$gateway = $this->gateway();
		$context = $gateway ? ApplePay::express_context( $gateway, 'cart' ) : null;
		if ( null !== $context ) {
			$this->render( $context, 'after' );
		}
	}

	/**
	 * Top of the classic checkout.
	 */
	public function checkout() {
		$this->render_checkout( 'after' );
	}

	/**
	 * CheckoutWC's express area, which draws its own separator.
	 */
	public function checkout_cfw() {
		$this->render_checkout( '' );
	}

	/**
	 * Renders the checkout button once per page.
	 *
	 * @param string $divider Where the "or" divider goes: before, after, or empty for none.
	 */
	private function render_checkout( string $divider ) {
		if ( $this->checkout_rendered ) {
			return;
		}
		$gateway = $this->gateway();
		$context = $gateway ? ApplePay::express_context( $gateway, 'checkout' ) : null;
		if ( null === $context ) {
			return;
		}
		$this->checkout_rendered = true;
		$this->render( $context, $divider );
	}

	/**
	 * Prints one express container.
	 *
	 * @param array  $context ApplePay::express_context() result.
	 * @param string $divider before|after|'' .
	 */
	private function render( array $context, string $divider ) {
		$or = '<p class="paradox-cardpointe-apple-pay-divider"><span>' . esc_html__( 'or', 'paradox-cardpointe-gateway-for-woocommerce' ) . '</span></p>';
		?>
		<div class="paradox-cardpointe-apple-pay-express is-<?php echo esc_attr( $context['location'] ); ?>"
			data-location="<?php echo esc_attr( $context['location'] ); ?>"
			data-amount="<?php echo esc_attr( $context['amount'] ); ?>"
			data-currency="<?php echo esc_attr( $context['currency'] ); ?>"
			data-needs-shipping="<?php echo $context['needs_shipping'] ? '1' : '0'; ?>"
			data-product-id="<?php echo esc_attr( (string) $context['product_id'] ); ?>"
			data-product-type="<?php echo esc_attr( $context['product_type'] ); ?>"
			hidden>
			<?php echo 'before' === $divider ? $or : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
			<div class="paradox-cardpointe-apple-pay-slot"></div>
			<div class="paradox-cardpointe-apple-pay-message woocommerce-error" role="alert" aria-live="assertive" hidden></div>
			<?php echo 'after' === $divider ? $or : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
		</div>
		<?php
	}
}
