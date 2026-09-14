<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use JovepayPlugin\Util\DebugLog;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/*
 * Shopware 6.7+ uses AbstractPaymentHandler; 6.5/6.6 use AsynchronousPaymentHandlerInterface.
 * Define the concrete handler class for whichever API the installed Shopware version exposes.
 */
if (class_exists(AbstractPaymentHandler::class)) {
    class Jovepay extends AbstractPaymentHandler
    {
        use JovepayPaymentTrait;

        public function __construct(
            OrderTransactionStateHandler $transactionStateHandler,
            $currencyRepository,
            $orderRepository,
            $orderTransactionRepository,
            RouterInterface $router,
            SystemConfigService $systemConfigService,
            DebugLog $debugLog
        ) {
            $this->initJovepay(
                $transactionStateHandler,
                $currencyRepository,
                $orderRepository,
                $orderTransactionRepository,
                $router,
                $systemConfigService,
                $debugLog
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
                if (class_exists(\Shopware\Core\Checkout\Payment\PaymentException::class)
                    && $e instanceof \Shopware\Core\Checkout\Payment\PaymentException
                ) {
                    throw $e;
                }

                $this->debugLog->send(
                    'Error',
                    'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
                );

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

            $this->applyPaymentState($transaction->getOrderTransactionId(), $response, $context);
        }
    }
} elseif (interface_exists(AsynchronousPaymentHandlerInterface::class)) {
    class Jovepay implements AsynchronousPaymentHandlerInterface
    {
        use JovepayPaymentTrait;

        public function __construct(
            OrderTransactionStateHandler $transactionStateHandler,
            $currencyRepository,
            $orderRepository,
            $orderTransactionRepository,
            RouterInterface $router,
            SystemConfigService $systemConfigService,
            DebugLog $debugLog
        ) {
            $this->initJovepay(
                $transactionStateHandler,
                $currencyRepository,
                $orderRepository,
                $orderTransactionRepository,
                $router,
                $systemConfigService,
                $debugLog
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
                if (class_exists(\Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException::class)
                    && $e instanceof \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
                ) {
                    throw $e;
                }

                $this->debugLog->send(
                    'Error',
                    'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
                );

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

            $this->applyPaymentState(
                $transaction->getOrderTransaction()->getId(),
                $response,
                $salesChannelContext->getContext()
            );
        }
    }
} else {
    throw new \RuntimeException(
        'JOVEpay requires Shopware 6.5+ with AsynchronousPaymentHandlerInterface or AbstractPaymentHandler.'
    );
}
