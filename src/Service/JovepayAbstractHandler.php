<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use JovepayPlugin\Util\PaymentLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Shopware 6.7+ payment handler.
 */
class JovepayAbstractHandler extends AbstractPaymentHandler
{
    use JovepayPaymentTrait;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        $currencyRepository,
        $orderTransactionRepository,
        RouterInterface $router,
        SystemConfigService $systemConfigService,
        PaymentLogger $logger
    ) {
        $this->initJovepay(
            $transactionStateHandler,
            $currencyRepository,
            $orderTransactionRepository,
            $router,
            $systemConfigService,
            $logger
        );
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        try {
            $paymentData = $this->getPaymentDataFromTransactionId(
                $transaction->getOrderTransactionId(),
                $transaction->getReturnUrl(),
                $context
            );

            return $this->createGatewayRedirect(
                $paymentData,
                $transaction->getOrderTransactionId(),
                $context
            );
        } catch (\Throwable $e) {
            if ($e instanceof PaymentException) {
                throw $e;
            }

            $this->logger->error('JOVEpay payment process failed', [
                'orderTransactionId' => $transaction->getOrderTransactionId(),
                'exception' => $e->getMessage(),
            ]);

            throw $this->createAsyncProcessException(
                $transaction->getOrderTransactionId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage(),
                $e
            );
        }
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $requestParams = $this->getRequestParams($request);
        $requestContent = $this->getRequestContent($request);
        $requestContent = $this->isJson($requestContent) ? json_decode($requestContent, true) : [];
        $response = array_replace($requestParams, \is_array($requestContent) ? $requestContent : []);

        // Browser return only: apply status if the gateway included it. Otherwise keep
        // in_progress and let the JOVEpay IPN (/checkout/jovepay/callback) set the final state.
        $this->applyPaymentState($transaction->getOrderTransactionId(), $response, $context, false);
    }
}
