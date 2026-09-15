<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use JovepayPlugin\Util\PaymentLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Shopware 6.2–6.6 async payment handler.
 */
class JovepayAsyncHandler implements AsynchronousPaymentHandlerInterface
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

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        try {
            $paymentData = $this->getPaymentDataFromAsyncStruct(
                $transaction,
                $salesChannelContext->getContext()
            );

            return $this->createGatewayRedirect(
                $paymentData,
                $transaction->getOrderTransaction()->getId(),
                $salesChannelContext->getContext()
            );
        } catch (\Throwable $e) {
            if ($e instanceof AsyncPaymentProcessException) {
                throw $e;
            }

            $this->logger->error('JOVEpay payment process failed', [
                'orderTransactionId' => $transaction->getOrderTransaction()->getId(),
                'exception' => $e->getMessage(),
            ]);

            throw $this->createAsyncProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage(),
                $e
            );
        }
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $requestParams = $this->getRequestParams($request);
        $requestContent = $this->getRequestContent($request);
        $requestContent = $this->isJson($requestContent) ? json_decode($requestContent, true) : [];
        $response = array_replace($requestParams, \is_array($requestContent) ? $requestContent : []);

        // Browser return only: apply status if present; otherwise await JOVEpay IPN.
        $this->applyPaymentState(
            $transaction->getOrderTransaction()->getId(),
            $response,
            $salesChannelContext->getContext(),
            false
        );
    }
}
