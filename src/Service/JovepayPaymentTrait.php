<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use Shopware\Core\Checkout\Payment\PaymentException;
use JovepayPlugin\Util\PaymentLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Shared payment-gateway logic for Shopware 6.2–6.7 payment handlers.
 */
trait JovepayPaymentTrait
{
    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var EntityRepository|object */
    private $currencyRepository;

    /** @var EntityRepository|object */
    private $orderTransactionRepository;

    /** @var RouterInterface */
    private $router;

    /** @var PaymentLogger */
    private $logger;

    /**
     * @param EntityRepository|object $currencyRepository
     * @param EntityRepository|object $orderTransactionRepository
     */
    private function initJovepay(
        OrderTransactionStateHandler $transactionStateHandler,
        $currencyRepository,
        $orderTransactionRepository,
        RouterInterface $router,
        SystemConfigService $systemConfigService,
        PaymentLogger $logger
    ): void {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->currencyRepository = $currencyRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->router = $router;
        $this->logger = $logger;
    }

    public function createPaymentToken(array $payment_data): string
    {
        unset($payment_data['successUrl'], $payment_data['cancelUrl'], $payment_data['products']);

        return sha1(implode('|', $payment_data));
    }

    public function getProductsArray(iterable $lineItems, string $orderId): array
    {
        $jovepayLineItems = [];

        foreach ($lineItems as $item) {
            $jovepayLineItems[] = [
                'id' => $item->getIdentifier(),
                'order_id' => $orderId,
                'name' => $item->getLabel(),
                'product_id' => $item->getProductId(),
                'variation_id' => $item->getReferencedId(),
                'quantity' => $item->getQuantity(),
                'tax_class' => '',
                'subtotal' => round($item->getTotalPrice(), 6),
                'subtotal_tax' => '0',
                'total' => round($item->getTotalPrice(), 6),
                'total_tax' => '0',
            ];
        }

        return $jovepayLineItems;
    }

    private function loadOrderTransaction(string $orderTransactionId, Context $context): ?OrderTransactionEntity
    {
        $criteria = (new Criteria([$orderTransactionId]))
            ->addAssociation('order')
            ->addAssociation('order.orderCustomer')
            ->addAssociation('order.lineItems')
            ->addAssociation('order.currency')
            ->addAssociation('order.salesChannel');

        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        return $transaction;
    }

    private function resolveCurrencyCode(OrderEntity $orderEntity, Context $context): string
    {
        $currency = $orderEntity->getCurrency();
        if ($currency !== null) {
            return $currency->getIsoCode() ?: 'EUR';
        }

        $currencyId = $orderEntity->getCurrencyId();
        $currencyResult = $this->currencyRepository->search(
            new Criteria([$currencyId]),
            $context
        );
        $currencyEntity = $currencyResult->first();

        return $currencyEntity !== null ? ($currencyEntity->getIsoCode() ?: 'EUR') : 'EUR';
    }

    private function buildPaymentDataFromOrder(
        OrderEntity $orderEntity,
        string $orderTransactionId,
        ?string $returnUrl,
        Context $context
    ): array {
        $currencyCode = $this->resolveCurrencyCode($orderEntity, $context);

        $callBackUrl = '';
        $successURL = '';
        $cancelURL = '';

        if (!empty($returnUrl)) {
            // Browser success must hit Shopware's payment finalize URL (_sw_payment_token) so the
            // payment session completes. Final paid/failed state comes from JOVEpay IPN
            // (ipnCallbackUrl → /checkout/jovepay/callback), not from inventing a status on return.
            $successURL = $returnUrl;
            $cancelURL = $this->router->generate(
                'frontend.checkout.jovepay.error',
                ['_sw_order' => $orderTransactionId, 'paymentStatus' => 'cancelled'],
                RouterInterface::ABSOLUTE_URL
            );
            $callBackUrl = $this->router->generate(
                'frontend.checkout.jovepay.callback',
                ['_sw_order' => $orderTransactionId],
                RouterInterface::ABSOLUTE_URL
            );
        }

        $orderCustomer = $orderEntity->getOrderCustomer();
        $transmitCustomerData = (bool) $this->systemConfigService->get('JovepayPlugin.config.transmitCustomerData');
        $transmitProductData = (bool) $this->systemConfigService->get('JovepayPlugin.config.transmitProductData');

        $salesChannelName = $orderEntity->getSalesChannel() !== null
            ? $orderEntity->getSalesChannel()->getName()
            : 'Shopware';

        return [
            'dataSource' => 'shopware',
            'priceCurrency' => strtoupper($currencyCode),
            'successUrl' => $successURL,
            'ipnCallbackUrl' => $callBackUrl,
            'cancelUrl' => $cancelURL,
            'isTestnet' => (bool) $this->systemConfigService->get('JovepayPlugin.config.isTestnet'),
            'orderId' => $salesChannelName . '_' . $orderEntity->getOrderNumber(),
            'customerName' => $transmitCustomerData && $orderCustomer !== null
                ? trim($orderCustomer->getFirstName() . ' ' . $orderCustomer->getLastName())
                : '',
            'customerEmail' => $transmitCustomerData && $orderCustomer !== null
                ? (string) $orderCustomer->getEmail()
                : '',
            'orderDescription' => $orderTransactionId,
            'priceAmount' => round($orderEntity->getAmountTotal(), 6),
            'products' => $transmitProductData
                ? $this->getProductsArray($orderEntity->getLineItems() ?? [], (string) $orderEntity->getOrderNumber())
                : [],
        ];
    }

    /**
     * @param object $transaction AsyncPaymentTransactionStruct on Shopware 6.2–6.6
     */
    private function getPaymentDataFromAsyncStruct(object $transaction, Context $context): array
    {
        if (
            !method_exists($transaction, 'getOrder')
            || !method_exists($transaction, 'getOrderTransaction')
            || !method_exists($transaction, 'getReturnUrl')
        ) {
            throw new \InvalidArgumentException('Invalid async payment transaction struct');
        }

        /** @var OrderEntity $orderEntity */
        $orderEntity = $transaction->getOrder();
        $orderTransaction = $transaction->getOrderTransaction();

        return $this->buildPaymentDataFromOrder(
            $orderEntity,
            $orderTransaction->getId(),
            $transaction->getReturnUrl(),
            $context
        );
    }

    private function getPaymentDataFromTransactionId(
        string $orderTransactionId,
        ?string $returnUrl,
        Context $context
    ): array {
        $orderTransaction = $this->loadOrderTransaction($orderTransactionId, $context);
        if ($orderTransaction === null || $orderTransaction->getOrder() === null) {
            throw new \RuntimeException('Order transaction not found: ' . $orderTransactionId);
        }

        return $this->buildPaymentDataFromOrder(
            $orderTransaction->getOrder(),
            $orderTransactionId,
            $returnUrl,
            $context
        );
    }

    /**
     * @return string|false
     */
    public function createPaymentUrl(array $parameters, string $version)
    {
        $apiUrl = (string) $this->systemConfigService->get('JovepayPlugin.config.apiUrl');
        $apiKey = (string) $this->systemConfigService->get('JovepayPlugin.config.apiKey');

        if ($apiUrl === '') {
            $this->logger->error('JOVEpay API URL is missing');

            return false;
        }
        if ($apiKey === '') {
            $this->logger->error('JOVEpay API key is missing');

            return false;
        }

        $parameters['token'] = $this->createPaymentToken($parameters);
        $parameters['apiKey'] = $apiKey;
        $parameters['plugin_version'] = $version;

        $theme = $this->systemConfigService->get('JovepayPlugin.config.isDark') ? 'dark' : 'light';

        return $apiUrl . '?params=' . urlencode(base64_encode(json_encode($parameters))) . '&theme=' . $theme;
    }

    public function validatePayment($paymentResponse): bool
    {
        return true;
    }

    public function validateApiKey(): bool
    {
        return (bool) $this->systemConfigService->get('JovepayPlugin.config.apiKey');
    }

    private function createGatewayRedirect(array $paymentData, string $orderTransactionId, Context $context): RedirectResponse
    {
        if (!$this->validateApiKey()) {
            $this->logger->error('JOVEpay API key is not configured');
            $errorMsg = 'Payment could not be started. The merchant API key is not configured.';
            $route = $this->router->generate(
                'frontend.checkout.jovepay.error',
                ['message' => $errorMsg],
                RouterInterface::ABSOLUTE_URL
            );

            return new RedirectResponse($route);
        }

        $this->logger->info('Creating JOVEpay payment redirect', [
            'orderId' => $paymentData['orderId'] ?? null,
            'amount' => $paymentData['priceAmount'] ?? null,
            'currency' => $paymentData['priceCurrency'] ?? null,
        ]);
        $redirectUrl = $this->createPaymentUrl($paymentData, $this->getPluginVersion());

        if ($redirectUrl === false) {
            throw $this->createAsyncProcessException(
                $orderTransactionId,
                'An error occurred during the communication with external payment gateway'
            );
        }

        $this->transactionStateHandler->process($orderTransactionId, $context);
        $this->logger->info('Redirecting customer to JOVEpay gateway', [
            'orderTransactionId' => $orderTransactionId,
        ]);

        return new RedirectResponse($redirectUrl);
    }

    private function createAsyncProcessException(string $orderTransactionId, string $message, ?\Throwable $previous = null): \Throwable
    {
        if (
            \class_exists(PaymentException::class)
            && \in_array('asyncProcessInterrupted', \get_class_methods(PaymentException::class) ?: [], true)
        ) {
            return PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                $message,
                $previous
            );
        }

        $legacyException = 'Shopware\\Core\\Checkout\\Payment\\Exception\\AsyncPaymentProcessException';
        if (\class_exists($legacyException)) {
            return new $legacyException($orderTransactionId, $message, $previous);
        }

        return new \RuntimeException($message, 0, $previous);
    }

    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function getRequestParams(Request $request): array
    {
        return array_replace($request->request->all(), $request->query->all());
    }

    private function getRequestContent(Request $request): string
    {
        return $request->getContent();
    }

    /**
     * Apply a gateway payment status to the Shopware order transaction.
     *
     * @param bool $statusRequired When true (IPN), missing paymentStatus is ignored and logged.
     *                             When false (browser return via Shopware finalize), missing status
     *                             leaves the transaction in progress — JOVEpay IPN is authoritative.
     */
    private function applyPaymentState(
        string $orderTransactionId,
        array $response,
        Context $context,
        bool $statusRequired = false
    ): void {
        if (!isset($response['paymentStatus']) || $response['paymentStatus'] === '') {
            if ($statusRequired) {
                $this->logger->error('JOVEpay status update missing paymentStatus', [
                    'orderTransactionId' => $orderTransactionId,
                    'payload' => $response,
                ]);
            } else {
                $this->logger->info('JOVEpay browser return without paymentStatus; awaiting IPN', [
                    'orderTransactionId' => $orderTransactionId,
                ]);
            }

            return;
        }

        $paymentState = (string) $response['paymentStatus'];

        if ($paymentState === 'finished' || $paymentState === 'confirmed' || $paymentState === 'sending') {
            if ($this->validatePayment($response['paymentStatus'])) {
                try {
                    $this->transactionStateHandler->paid($orderTransactionId, $context);
                    $this->logger->info('JOVEpay marked transaction paid', [
                        'orderTransactionId' => $orderTransactionId,
                        'paymentStatus' => $paymentState,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('JOVEpay failed to mark transaction paid', [
                        'orderTransactionId' => $orderTransactionId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($paymentState === 'partially_paid') {
            try {
                $this->markPaidPartially($orderTransactionId, $context);
                $this->logger->info('JOVEpay marked transaction partially paid', [
                    'orderTransactionId' => $orderTransactionId,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('JOVEpay failed to mark transaction partially paid', [
                    'orderTransactionId' => $orderTransactionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        } elseif ($paymentState === 'failed' || $paymentState === 'refunded' || $paymentState === 'expired' || $paymentState === 'cancelled') {
            try {
                $this->transactionStateHandler->fail($orderTransactionId, $context);
                $this->logger->info('JOVEpay marked transaction failed', [
                    'orderTransactionId' => $orderTransactionId,
                    'paymentStatus' => $paymentState,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('JOVEpay failed to mark transaction failed', [
                    'orderTransactionId' => $orderTransactionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        } else {
            $this->logger->error('JOVEpay received unknown paymentStatus', [
                'orderTransactionId' => $orderTransactionId,
                'paymentStatus' => $paymentState,
            ]);
        }
    }

    private function markPaidPartially(string $orderTransactionId, Context $context): void
    {
        $methods = \get_class_methods($this->transactionStateHandler) ?: [];
        $method = \in_array('paidPartially', $methods, true) ? 'paidPartially' : 'payPartially';
        $this->transactionStateHandler->{$method}($orderTransactionId, $context);
    }

    public function isValidToken(string $response_token, string $token): bool
    {
        return hash_equals($token, $response_token);
    }

    private function getPluginVersion(): string
    {
        return '1.0.0';
    }
}
