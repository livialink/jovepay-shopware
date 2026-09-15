<?php declare(strict_types=1);

/**
 * Runtime polyfills for Shopware payment APIs removed in 6.7.
 * Only defined when the real classes are absent, so PHPStan can analyse
 * the 6.2–6.6 async handler against a typed surface on 6.7 testenvs.
 */

namespace Shopware\Core\Checkout\Payment\Cart {
    if (!\class_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\AsyncPaymentTransactionStruct')) {
        class AsyncPaymentTransactionStruct
        {
            /** @return object */
            public function getOrder()
            {
                throw new \BadMethodCallException('Compatibility stub');
            }

            /** @return object */
            public function getOrderTransaction()
            {
                throw new \BadMethodCallException('Compatibility stub');
            }

            public function getReturnUrl(): string
            {
                return '';
            }
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Cart\PaymentHandler {
    if (!\interface_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\AsynchronousPaymentHandlerInterface')) {
        interface AsynchronousPaymentHandlerInterface
        {
        }
    }
}

namespace Shopware\Core\Checkout\Payment\Exception {
    if (!\class_exists('Shopware\\Core\\Checkout\\Payment\\Exception\\AsyncPaymentProcessException')) {
        class AsyncPaymentProcessException extends \RuntimeException
        {
            /** @var mixed */
            private $orderTransactionId;

            /** @param mixed $orderTransactionId */
            public function __construct($orderTransactionId, string $message, ?\Throwable $previous = null)
            {
                $this->orderTransactionId = $orderTransactionId;
                parent::__construct($message, 0, $previous);
            }

            /** @return mixed */
            public function getOrderTransactionId()
            {
                return $this->orderTransactionId;
            }
        }
    }
}
