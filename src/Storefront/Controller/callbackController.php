<?php declare(strict_types=1);

namespace JovepayPlugin\Storefront\Controller;

use JovepayPlugin\Util\PaymentLogger;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Payment return / IPN endpoints. Routes are declared in Resources/config/routes.yaml
 * so the same controller works from Shopware 6.2 through 6.7.
 *
 * Class-level scope for Shopware < 6.4.11 (YAML array defaults alone break there).
 * RouteScopeCompatSubscriber also normalizes `_routeScope` at runtime.
 *
 * @\Shopware\Core\Framework\Routing\Annotation\RouteScope(scopes={"storefront"})
 */
class CallbackController extends StorefrontController
{
    /** @var PaymentLogger */
    private $logger;

    /** @var object */
    private $orderTransactionRepository;

    /** @var OrderTransactionStateHandler */
    private $transactionStateHandler;

    /**
     * @param object $orderTransactionRepository
     */
    public function __construct(
        PaymentLogger $logger,
        $orderTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->logger = $logger;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    public function error(Request $request, SalesChannelContext $context): Response
    {
        $this->logger->logRequest('JOVEpay error page requested', $request);

        $payload = $this->mergeRequestPayload($request, [
            'paymentStatus' => 'cancelled',
            'message' => '',
            'redirectURL' => '/',
            '_sw_order' => '',
        ]);

        $userMessage = (string) ($payload['message'] ?? '');
        $payload['message'] = '<h1 class="finish-header jovepay-callback__error-title">'
            . 'We could not confirm your payment.'
            . '</h1>'
            . '<h3 class="finish-header jovepay-callback__error-title">'
            . 'Please contact the shop with your order details.'
            . '</h3>'
            . '<div class="jovepay-callback__error-detail"><pre>'
            . htmlspecialchars($userMessage, ENT_QUOTES, 'UTF-8')
            . '</pre></div>';

        return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', $payload);
    }

    public function process(Request $request, SalesChannelContext $context): Response
    {
        try {
            $payload = $this->mergeRequestPayload($request, [
                'message' => '',
                'redirectURL' => '/',
                'paymentStatus' => 'in_progress',
                '_sw_order' => '',
            ]);

            // Legacy success URLs included _sw_payment_token on this route. Hand off to Shopware
            // payment finalize so the payment handler runs and the customer reaches the finish page.
            if (!empty($payload['_sw_payment_token'])) {
                return $this->redirectToShopwarePaymentFinalize((string) $payload['_sw_payment_token']);
            }

            if (
                isset($payload['_sw_order'], $payload['paymentStatus'], $payload['purchase_id'])
                && \in_array($payload['paymentStatus'], ['finished', 'confirmed', 'sending'], true)
            ) {
                return $this->finalizeTransactionInternal($request, $context, false);
            }

            if (!empty($payload['_sw_order'])) {
                $criteria = (new Criteria([(string) $payload['_sw_order']]))->addAssociation('stateMachineState');
                $data = $this->orderTransactionRepository->search($criteria, $context->getContext());
                if ($data->getTotal() > 0) {
                    $transaction = $data->first();
                    $state = $transaction->getStateMachineState();
                    $status = $state !== null ? $state->getTechnicalName() : 'in_progress';
                    $orderId = $transaction->getOrderId();
                    $payload['paymentStatus'] = $status;

                    if ($status === 'paid' && $orderId) {
                        return $this->forwardToRoute('frontend.checkout.finish.page', ['orderId' => $orderId]);
                    }
                }
            }

            return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', $payload);
        } catch (\Throwable $e) {
            $this->logger->error('JOVEpay process page failed', ['exception' => $e->getMessage()]);

            return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', [
                'paymentStatus' => 'failed',
                'message' => 'We could not confirm your payment. Please contact the shop with your order details.',
                'redirectURL' => '/',
                '_sw_order' => (string) $request->query->get('_sw_order', ''),
            ]);
        }
    }

    public function processCheck(Request $request, SalesChannelContext $context): Response
    {
        try {
            $payload = $this->mergeRequestPayload($request);

            if (!isset($payload['_sw_order'])) {
                return new JsonResponse(null, Response::HTTP_NO_CONTENT);
            }

            $criteria = (new Criteria([(string) $payload['_sw_order']]))->addAssociation('stateMachineState');
            $data = $this->orderTransactionRepository->search($criteria, $context->getContext());
            if ($data->getTotal() === 0) {
                return new JsonResponse(null, Response::HTTP_NO_CONTENT);
            }

            $state = $data->first()->getStateMachineState();
            $status = $state !== null ? $state->getTechnicalName() : 'in_progress';

            return new JsonResponse([
                '_sw_order' => $payload['_sw_order'],
                'payment_status' => $status,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('JOVEpay process check failed', ['exception' => $e->getMessage()]);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }
    }

    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->finalizeTransactionInternal($request, $salesChannelContext, true);
    }

    private function redirectToShopwarePaymentFinalize(string $paymentToken): Response
    {
        $router = $this->container->get('router');

        foreach (['payment.finalize.transaction', 'frontend.payment.finalize.transaction'] as $routeName) {
            try {
                $url = $router->generate(
                    $routeName,
                    ['_sw_payment_token' => $paymentToken],
                    RouterInterface::ABSOLUTE_PATH
                );

                return new RedirectResponse($url);
            } catch (\Throwable $ignored) {
                // Try next known route name.
            }
        }

        // Fallback path used by Shopware 6.4–6.7 payment finalize.
        return new RedirectResponse('/payment/finalize-transaction?' . http_build_query([
            '_sw_payment_token' => $paymentToken,
        ]));
    }

    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function mergeRequestPayload(Request $request, array $defaults = []): array
    {
        $content = $request->getContent();
        $json = $this->isJson($content) ? json_decode($content, true) : [];
        if (!\is_array($json)) {
            $json = [];
        }

        return array_replace($defaults, $request->request->all(), $request->query->all(), $json);
    }

    private function emptyFinalizeResponse(bool $isRest, array $viewData = []): Response
    {
        if ($isRest) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return $this->renderStorefront(
            '@JovepayPlugin/callback/index.html.twig',
            array_replace([
                'message' => '',
                'redirectURL' => '/',
                'csrf_protected' => false,
                'paymentStatus' => 'in_progress',
                '_sw_order' => '',
            ], $viewData)
        );
    }

    private function finalizeSuccessResponse(
        bool $isRest,
        string $transactionId,
        string $paymentState,
        string $orderId
    ): Response {
        if ($isRest) {
            return new JsonResponse(['_sw_order' => $transactionId, 'payment_status' => $paymentState]);
        }

        return $this->forwardToRoute('frontend.checkout.finish.page', ['orderId' => $orderId]);
    }

    private function finalizeTransactionInternal(
        Request $request,
        SalesChannelContext $salesChannelContext,
        bool $isRest
    ): Response {
        $this->logger->logRequest('JOVEpay IPN/callback received', $request);

        try {
            $response = $this->mergeRequestPayload($request);

            if (!isset($response['_sw_order']) || $response['_sw_order'] === '') {
                $this->logger->error('JOVEpay IPN missing _sw_order', $response);

                return $this->emptyFinalizeResponse($isRest, $response);
            }

            // IPN is authoritative: never invent a status. Without paymentStatus, acknowledge and wait.
            if (!isset($response['paymentStatus']) || $response['paymentStatus'] === '') {
                $this->logger->error('JOVEpay IPN missing paymentStatus', $response);

                return $this->emptyFinalizeResponse($isRest, $response);
            }

            $paymentState = (string) $response['paymentStatus'];
            $context = $salesChannelContext->getContext();
            $orderTransactionId = (string) $response['_sw_order'];
            $criteria = (new Criteria([$orderTransactionId]))->addAssociation('stateMachineState');
            $transactionResult = $this->orderTransactionRepository->search($criteria, $context);

            if ($transactionResult->getTotal() === 0) {
                $this->logger->error('JOVEpay IPN order transaction not found', ['_sw_order' => $orderTransactionId]);

                return $this->emptyFinalizeResponse($isRest, $response);
            }

            $transaction = $transactionResult->first();
            $state = $transaction->getStateMachineState();
            $currentStatus = $state !== null ? $state->getTechnicalName() : 'in_progress';
            $orderId = (string) $transaction->getOrderId();

            // Idempotent success: already paid and IPN confirms success.
            if ($currentStatus === 'paid') {
                if (\in_array($paymentState, ['finished', 'confirmed', 'sending', 'paid'], true)) {
                    return $this->finalizeSuccessResponse($isRest, $orderTransactionId, $currentStatus, $orderId);
                }

                $this->logger->info('JOVEpay IPN received after paid; applying late status', [
                    '_sw_order' => $orderTransactionId,
                    'currentStatus' => $currentStatus,
                    'paymentStatus' => $paymentState,
                ]);
            }

            if (\in_array($currentStatus, ['in_progress', 'process', 'open', 'unconfirmed', 'paid'], true)) {
                if (\in_array($paymentState, ['finished', 'confirmed', 'sending'], true)) {
                    if ($currentStatus !== 'paid') {
                        try {
                            $this->transactionStateHandler->paid($orderTransactionId, $context);
                        } catch (\Throwable $e) {
                            $this->logger->error('JOVEpay IPN paid transition failed', [
                                '_sw_order' => $orderTransactionId,
                                'exception' => $e->getMessage(),
                            ]);
                        }
                    }

                    return $this->finalizeSuccessResponse($isRest, $orderTransactionId, $paymentState, $orderId);
                }

                if ($paymentState === 'partially_paid') {
                    try {
                        $this->markPaidPartially($orderTransactionId, $context);
                    } catch (\Throwable $e) {
                        $this->logger->error('JOVEpay IPN partially paid transition failed', [
                            '_sw_order' => $orderTransactionId,
                            'exception' => $e->getMessage(),
                        ]);
                    }

                    return $this->finalizeSuccessResponse($isRest, $orderTransactionId, $paymentState, $orderId);
                }

                if (\in_array($paymentState, ['failed', 'refunded', 'expired', 'cancelled'], true)) {
                    try {
                        $this->transactionStateHandler->fail($orderTransactionId, $context);
                    } catch (\Throwable $e) {
                        $this->logger->error('JOVEpay IPN fail transition failed', [
                            '_sw_order' => $orderTransactionId,
                            'exception' => $e->getMessage(),
                        ]);
                    }

                    return $this->finalizeSuccessResponse($isRest, $orderTransactionId, $paymentState, $orderId);
                }
            }

            $this->logger->error('JOVEpay IPN could not apply status', [
                '_sw_order' => $orderTransactionId,
                'currentStatus' => $currentStatus,
                'paymentStatus' => $paymentState,
            ]);

            return $this->emptyFinalizeResponse($isRest, array_replace($response, ['paymentStatus' => $currentStatus]));
        } catch (\Throwable $e) {
            $this->logger->error('JOVEpay IPN processing failed', ['exception' => $e->getMessage()]);

            return $this->emptyFinalizeResponse($isRest);
        }
    }

    private function markPaidPartially(string $orderTransactionId, Context $context): void
    {
        $methods = \get_class_methods($this->transactionStateHandler) ?: [];
        $method = \in_array('paidPartially', $methods, true) ? 'paidPartially' : 'payPartially';
        $this->transactionStateHandler->{$method}($orderTransactionId, $context);
    }
}
