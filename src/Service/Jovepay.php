<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;

/*
 * Bind JovepayPlugin\Service\Jovepay to the correct handler for this Shopware version.
 * 6.7+: subclass of AbstractPaymentHandler
 * 6.2–6.6: alias of AsynchronousPaymentHandlerInterface implementation
 */
if (!\class_exists(Jovepay::class, false)) {
    if (\class_exists(AbstractPaymentHandler::class)) {
        class Jovepay extends JovepayAbstractHandler
        {
        }
    } elseif (\interface_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\AsynchronousPaymentHandlerInterface')) {
        \class_alias(JovepayAsyncHandler::class, Jovepay::class);
    } else {
        throw new \RuntimeException(
            'JOVEpay requires Shopware 6.2+ with AsynchronousPaymentHandlerInterface or AbstractPaymentHandler.'
        );
    }
}
