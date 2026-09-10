<?php
/**
 * Payment exception.
 *
 * @package ParadoxSolutions\CardPointe
 */

namespace ParadoxSolutions\CardPointe\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a payment cannot proceed. The message is safe to show to the customer.
 */
class PaymentException extends \Exception {}
