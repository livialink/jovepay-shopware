<?php declare(strict_types=1);

namespace JovepayPlugin\Service;

use JovepayPlugin\Util\DebugLog;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Shared payment-gateway logic used by both Shopware 6.5/6.6 (async interface)
 * and Shopware 6.7+ (AbstractPaymentHandler) payment handlers.
 */
trait JovepayPaymentTrait
{
    private OrderTransactionStateHandler $transactionStateHandler;

    private SystemConfigService $systemConfigService;

    /** @var EntityRepository|object */
    private $currencyRepository;

    /** @var EntityRepository|object */
    private $orderRepository;

    /** @var EntityRepository|object */
    private $orderTransactionRepository;

    private RouterInterface $router;

    private DebugLog $debugLog;

    /**
     * @param EntityRepository|object $currencyRepository
     * @param EntityRepository|object $orderRepository
     * @param EntityRepository|object $orderTransactionRepository
     */
    private function initJovepay(
        OrderTransactionStateHandler $transactionStateHandler,
        $currencyRepository,
        $orderRepository,
        $orderTransactionRepository,
        RouterInterface $router,
        SystemConfigService $systemConfigService,
        DebugLog $debugLog
    ): void {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->currencyRepository = $currencyRepository;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->router = $router;
        $this->debugLog = $debugLog;
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

    private function loadOrderById(string $orderId, Context $context): ?OrderEntity
    {
        $criteria = (new Criteria([$orderId]))
            ->addAssociation('orderCustomer')
            ->addAssociation('lineItems')
            ->addAssociation('currency')
            ->addAssociation('salesChannel');

        /** @var OrderEntity|null $order */
        $order = $this->orderRepository->search($criteria, $context)->first();

        return $order;
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
        if ($orderEntity->getCurrency() !== null) {
            return $orderEntity->getCurrency()->getIsoCode() ?: 'EUR';
        }

        if ($orderEntity->getCurrencyId() === null) {
            return 'EUR';
        }

        $currencyResult = $this->currencyRepository->search(
            new Criteria([$orderEntity->getCurrencyId()]),
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
            parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $returnQuery);

            $callBackUrl = $this->router->generate(
                'frontend.checkout.jovepay.callback',
                ['_sw_order' => $orderTransactionId],
                RouterInterface::ABSOLUTE_URL
            );
            $successURL = $this->router->generate(
                'frontend.checkout.jovepay.process',
                array_replace(['_sw_order' => $orderTransactionId], $returnQuery),
                RouterInterface::ABSOLUTE_URL
            );
            $cancelURL = $this->router->generate(
                'frontend.checkout.jovepay.process',
                array_replace(['_sw_order' => $orderTransactionId], $returnQuery),
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

    private function getPaymentDataFromAsyncStruct(AsyncPaymentTransactionStruct $transaction, Context $context): array
    {
        $orderEntity = $transaction->getOrder();
        $loaded = $this->loadOrderById($orderEntity->getId(), $context);

        return $this->buildPaymentDataFromOrder(
            $loaded ?? $orderEntity,
            $transaction->getOrderTransaction()->getId(),
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
            $this->debugLog->send('Error', '[JovepayPlugin] ApiURL missing');
            return false;
        }
        if ($apiKey === '') {
            $this->debugLog->send('Error', '[JovepayPlugin] ApiKey missing');
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
            $errorMsg = 'Please Contact Support there has been an Error. <br>- jovepay.com API Key is not Set.';
            $route = $this->router->generate(
                'frontend.checkout.jovepay.error',
                ['message' => $errorMsg],
                RouterInterface::ABSOLUTE_URL
            );

            return new RedirectResponse($route);
        }

        $this->debugLog->send('paymentData', $paymentData);
        $redirectUrl = $this->createPaymentUrl($paymentData, $this->getPluginVersion());
        $this->debugLog->send('redirectUrl', $redirectUrl);

        if ($redirectUrl === false) {
            throw $this->createAsyncProcessException(
                $orderTransactionId,
                'An error occurred during the communication with external payment gateway'
            );
        }

        $this->transactionStateHandler->process($orderTransactionId, $context);
        $this->debugLog->send('Redirect to external gateway', date('m/d/Y h:i:s a', time()));

        return new RedirectResponse($redirectUrl);
    }

    private function createAsyncProcessException(string $orderTransactionId, string $message, ?\Throwable $previous = null): \Throwable
    {
        if (class_exists(\Shopware\Core\Checkout\Payment\PaymentException::class)) {
            return \Shopware\Core\Checkout\Payment\PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                $message,
                $previous
            );
        }

        return new \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException(
            $orderTransactionId,
            $message,
            $previous
        );
    }

    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param Request|object $request
     */
    private function getRequestParams($request): array
    {
        if ($request instanceof Request) {
            return array_replace($request->request->all(), $request->query->all());
        }

        if (\is_object($request) && method_exists($request, 'all')) {
            return $request->all();
        }

        return [];
    }

    /**
     * @param Request|object $request
     */
    private function getRequestContent($request): string
    {
        if ($request instanceof Request) {
            return $request->getContent();
        }

        return '';
    }

    private function applyPaymentState(string $orderTransactionId, array $response, Context $context): void
    {
        if (!isset($response['paymentStatus'])) {
            return;
        }

        $paymentState = $response['paymentStatus'];

        if ($paymentState === 'finished' || $paymentState === 'confirmed') {
            if ($this->validatePayment($response['paymentStatus'])) {
                $this->transactionStateHandler->paid($orderTransactionId, $context);
            }
        } elseif ($paymentState === 'partially_paid') {
            $this->transactionStateHandler->payPartially($orderTransactionId, $context);
        } elseif ($paymentState === 'failed' || $paymentState === 'refunded' || $paymentState === 'expired') {
            $this->transactionStateHandler->fail($orderTransactionId, $context);
        }
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
