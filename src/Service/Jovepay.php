<?php

declare(strict_types=1);

namespace JovepayPlugin\Service;

use JovepayPlugin\Util\DebugLog;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

class Jovepay
{
    private const PLUGIN_VERSION = '1.0.0';

    private OrderTransactionStateHandler $transactionStateHandler;

    private SystemConfigService $systemConfigService;

  /** @var object */
    private $currencyRepository;

  /** @var object */
    private $orderRepository;

    private RouterInterface $router;

    private DebugLog $debugLog;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        $currencyRepository,
        $orderRepository,
        RouterInterface $router,
        SystemConfigService $systemConfigService,
        DebugLog $debugLog
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->systemConfigService = $systemConfigService;
        $this->currencyRepository = $currencyRepository;
        $this->orderRepository = $orderRepository;
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
        $JovepayLineItems = [];

        foreach ($lineItems as $item) {
            $JovepayLineItems[] = [
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

        return $JovepayLineItems;
    }

    private function loadOrder(OrderEntity $order, SalesChannelContext $salesChannelContext): OrderEntity
    {
        $criteria = (new Criteria([$order->getId()]))
            ->addAssociation('orderCustomer')
            ->addAssociation('lineItems')
            ->addAssociation('currency');

        $loadedOrder = $this->orderRepository->search($criteria, $salesChannelContext->getContext())->first();

        return $loadedOrder ?? $order;
    }

    private function getPaymentData(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): array
    {
        $orderEntity = $this->loadOrder($transaction->getOrder(), $salesChannelContext);
        $returnUrl = $transaction->getReturnUrl();

        $currencyCode = 'EUR';
        if ($orderEntity->getCurrency() !== null) {
            $currencyCode = $orderEntity->getCurrency()->getIsoCode() ?: 'EUR';
        } elseif ($orderEntity->getCurrencyId() !== null) {
            $currencyResult = $this->currencyRepository->search(
                new Criteria([$orderEntity->getCurrencyId()]),
                $salesChannelContext->getContext()
            );
            $currencyEntity = $currencyResult->first();
            if ($currencyEntity !== null) {
                $currencyCode = $currencyEntity->getIsoCode() ?: 'EUR';
            }
        }

        $callBackUrl = '';
        $successURL = '';
        $cancelURL = '';

        if (!empty($returnUrl)) {
            parse_str((string) parse_url($returnUrl, PHP_URL_QUERY), $returnQuery);

            $transactionId = $transaction->getOrderTransaction()->getId();
            $callBackUrl = $this->router->generate(
                'frontend.checkout.jovepay.callback',
                ['_sw_order' => $transactionId],
                RouterInterface::ABSOLUTE_URL
            );
            $successURL = $this->router->generate(
                'frontend.checkout.jovepay.process',
                array_replace(['_sw_order' => $transactionId], $returnQuery),
                RouterInterface::ABSOLUTE_URL
            );
            $cancelURL = $this->router->generate(
                'frontend.checkout.jovepay.process',
                array_replace(['_sw_order' => $transactionId], $returnQuery),
                RouterInterface::ABSOLUTE_URL
            );
        }

        $orderCustomer = $orderEntity->getOrderCustomer();
        $transmitCustomerData = (bool) $this->systemConfigService->get('JovepayPlugin.config.transmitCustomerData');
        $transmitProductData = (bool) $this->systemConfigService->get('JovepayPlugin.config.transmitProductData');

        return [
            'dataSource' => 'shopware',
            'priceCurrency' => strtoupper($currencyCode),
            'successUrl' => $successURL,
            'ipnCallbackUrl' => $callBackUrl,
            'cancelUrl' => $cancelURL,
            'isTestnet' => (bool) $this->systemConfigService->get('JovepayPlugin.config.isTestnet'),
            'orderId' => $salesChannelContext->getSalesChannel()->getName() . '_' . $orderEntity->getOrderNumber(),
            'customerName' => $transmitCustomerData && $orderCustomer !== null
                ? trim($orderCustomer->getFirstName() . ' ' . $orderCustomer->getLastName())
                : '',
            'customerEmail' => $transmitCustomerData && $orderCustomer !== null
                ? (string) $orderCustomer->getEmail()
                : '',
            'orderDescription' => $transaction->getOrderTransaction()->getId(),
            'priceAmount' => round($orderEntity->getAmountTotal(), 6),
            'products' => $transmitProductData
                ? $this->getProductsArray($orderEntity->getLineItems() ?? [], $orderEntity->getOrderNumber())
                : [],
        ];
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

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        $request,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        if (!$this->validateApiKey()) {
            $errorMsg = 'Please Contact Support there has been an Error. <br>- jovepay.com API Key is not Set.';
            $route = $this->router->generate('frontend.checkout.jovepay.error', ['message' => $errorMsg], RouterInterface::ABSOLUTE_URL);

            return new RedirectResponse($route);
        }

        try {
            $paymentData = $this->getPaymentData($transaction, $salesChannelContext);
            $this->debugLog->send('paymentData', $paymentData);
            $redirectUrl = $this->createPaymentUrl($paymentData, self::PLUGIN_VERSION);
            $this->debugLog->send('redirectUrl', $redirectUrl);

            if ($redirectUrl === false) {
                throw new AsyncPaymentProcessException(
                    $transaction->getOrderTransaction()->getId(),
                    'An error occurred during the communication with external payment gateway'
                );
            }
        } catch (AsyncPaymentProcessException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->debugLog->send('Error', 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
            );
        }

        $this->transactionStateHandler->process($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
        $this->debugLog->send('Redirect to external gateway', date('m/d/Y h:i:s a', time()));

        return new RedirectResponse($redirectUrl);
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

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $requestqueryaRRAY = $this->getRequestParams($request);
        $requestContent = $this->getRequestContent($request);
        $requestContent = $this->isJson($requestContent) ? json_decode($requestContent, true) : [];
        $response = array_replace($requestqueryaRRAY, $requestContent);

        if (!isset($response['paymentStatus'])) {
            return;
        }

        $paymentState = $response['paymentStatus'];
        $context = $salesChannelContext->getContext();

        if ($paymentState === 'finished' || $paymentState === 'sending') {
            if ($this->validatePayment($response['paymentStatus'])) {
                $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $context);
            }
        } elseif ($paymentState === 'partially_paid') {
            $this->transactionStateHandler->payPartially($transaction->getOrderTransaction()->getId(), $context);
        } elseif ($paymentState === 'failed' || $paymentState === 'refunded' || $paymentState === 'expired') {
            $this->transactionStateHandler->fail($transaction->getOrderTransaction()->getId(), $context);
        }
    }

    public function isValidToken(string $response_token, string $token): bool
    {
        return hash_equals($token, $response_token);
    }
}
