<?php declare(strict_types=1);

namespace JovepayPlugin\Storefront\Controller;

use JovepayPlugin\Util\DebugLog;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * @RouteScope(scopes={"storefront"})
 */
class CallbackController extends StorefrontController
{
    private DebugLog $debugLog;

    /**
     * @var object
     */
    private EntityRepository $orderTransactionRepository;

    private OrderTransactionStateHandler $transactionStateHandler;

    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public function __construct(
        DebugLog $debugLog,
        EntityRepository $orderTransactionRepository,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->debugLog = $debugLog;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->transactionStateHandler = $transactionStateHandler;
    }

    /**
     * @Route(
     *     "/checkout/jovepay/error",
     *     name="frontend.checkout.jovepay.error",
     *     methods={"GET", "POST"},
     *     defaults={"csrf_protected"=false},
     *     options={"seo"=false}
     * )
     */
    public function error(Request $request, SalesChannelContext $context): Response
    {
        $this->debugLog->forwardCopy('Responce , error', $request);

        $requestqueryaRRAY = array_replace($request->request->all(), $request->query->all());
        $requestContent = $request->getContent();
        $requestContent = $this->isJson($requestContent) ? json_decode($request->getContent(), true) : [];
        $requestqueryaRRAY = array_replace(['paymentStatus' => 'cancelled', 'message' => '', 'redirectURL' => '/', 'csrf_protected' => false], $requestqueryaRRAY, $requestContent);

        $requestqueryaRRAY['message'] = '<h1 class="finish-header" style="color: red;">'
            . 'we faced some techinical error confirming your order.'
            . '</h1>'
            . '<h3 class="finish-header" style="color: red;">'
            . 'please contact Support with your order detail'
            . '</h3>'
            . '<div class="container" style="max-width: 700px;background: bisque;border-radius: 5px;padding: 10px;"><pre>' . $requestqueryaRRAY['message'] . '</pre></div>';

        return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', $requestqueryaRRAY);
    }

    /**
     * @Route(
     *     "/checkout/jovepay/process",
     *     name="frontend.checkout.jovepay.process",
     *     methods={"GET", "POST"},
     *     defaults={"csrf_protected"=false},
     *     options={"seo"=false}
     * )
     */
    public function process(Request $request, SalesChannelContext $context): Response
    {
        $requestqueryaRRAY = array_replace($request->request->all(), $request->query->all());
        $requestContent = $request->getContent();
        $requestContent = $this->isJson($requestContent) ? json_decode($request->getContent(), true) : [];

        $requestqueryaRRAY = array_replace(
            ['message' => '', 'redirectURL' => '/', 'csrf_protected' => false],
            $requestqueryaRRAY,
            $requestContent
        );

        if (isset($requestqueryaRRAY['_sw_order']) && isset($requestqueryaRRAY['paymentStatus']) && isset($requestqueryaRRAY['purchase_id'])) {
            if ($requestqueryaRRAY['paymentStatus'] === 'finished' || $requestqueryaRRAY['paymentStatus'] === 'sending') {
                return $this->_finalizeTransaction($request, $context, false);
            }
        }

        if (isset($requestqueryaRRAY['_sw_order'])) {
            $oStartus = OrderStates::STATE_CANCELLED;
            $criteria = (new Criteria([$requestqueryaRRAY['_sw_order']]))->addAssociation('stateMachineState');
            $data = $this->orderTransactionRepository->search($criteria, $context->getContext());
            if ($data->getTotal() > 0) {
                $oStartus = $data->first()->getStateMachineState()->getTechnicalName();
                $orderId = $data->first()->getOrderId();
                $requestqueryaRRAY = array_replace($requestqueryaRRAY, ['paymentStatus' => $oStartus]);
                if ($oStartus === 'paid') {
                    return $this->forwardToRoute('frontend.checkout.finish.page', ['orderId' => $orderId]);
                }
            }
        }
        return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', $requestqueryaRRAY);
    }

    /**
     * @Route(
     *     "/checkout/jovepay/process/check",
     *     name="frontend.checkout.jovepay.process.check",
     *     methods={"POST"},
     *     defaults={"csrf_protected"=false},
     *     options={"seo"=false}
     * )
     */
    public function processCheck(Request $request, SalesChannelContext $context): Response
    {
        try {
            $requestqueryaRRAY = array_replace($request->request->all(), $request->query->all());
            $requestContent = $request->getContent();
            $requestContent = $this->isJson($requestContent) ? json_decode($request->getContent(), true) : [];

            $requestqueryaRRAY = array_replace(['paymentStatus' => 'cancelled', 'message' => '', 'redirectURL' => '/', 'csrf_protected' => false], $requestqueryaRRAY, $requestContent);

            if (isset($requestqueryaRRAY['_sw_order'])) {
                $oStartus = OrderStates::STATE_CANCELLED;
                $criteria = (new Criteria([$requestqueryaRRAY['_sw_order']]))->addAssociation('stateMachineState');
                $data = $this->orderTransactionRepository->search($criteria, $context->getContext());
                if ($data->getTotal() > 0) {
                    $oStartus = $data->first()->getStateMachineState()->getTechnicalName();

                    return new JsonResponse(['_sw_order' => $requestqueryaRRAY['_sw_order'], 'payment_status' => $oStartus]);
                }
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            $this->debugLog->send('Error', $e->getMessage());

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }
    }

    public function validatePayment(string $paymentID, string $paymentState): bool
    {
        return true;
    }

    private function emptyFinalizeResponse(bool $isRest, array $viewData = []): Response
    {
        if ($isRest) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return $this->renderStorefront('@JovepayPlugin/callback/index.html.twig', array_replace(
            ['message' => '', 'redirectURL' => '/', 'csrf_protected' => false],
            $viewData
        ));
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

    private function _finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext, bool $isRest): Response
    {
        $this->debugLog->forwardCopy('Responce , callback', $request);
        try {
            $requestqueryaRRAY = array_replace($request->request->all(), $request->query->all());
            $requestContent = $request->getContent();
            $requestContent = $this->isJson($requestContent) ? json_decode($request->getContent(), true) : [];
            $response = array_replace($requestqueryaRRAY, $requestContent);
            if (!isset($requestqueryaRRAY['_sw_order'])) {
                $this->debugLog->send('Error , callback , we dont have sw order', $response);

                return $this->emptyFinalizeResponse($isRest, $requestqueryaRRAY);
            }
            if (!isset($response['paymentStatus'])) {
                $this->debugLog->send('Error , callback , we dont have payment status', $response);

                return $this->emptyFinalizeResponse($isRest, $requestqueryaRRAY);
            }

            $paymentState = $response['paymentStatus'];
            $context = $salesChannelContext->getContext();

            $oStartus = OrderStates::STATE_CANCELLED;
            $criteria = (new Criteria([$requestqueryaRRAY['_sw_order']]))->addAssociation('stateMachineState');
            $transaction = $this->orderTransactionRepository->search($criteria, $context);

            if ($transaction->getTotal() > 0) {
                $oStartus = $transaction->first()->getStateMachineState()->getTechnicalName();
                $orderId = $transaction->first()->getOrderId();

                if ($oStartus === 'paid') {
                    return $this->finalizeSuccessResponse($isRest, $requestqueryaRRAY['_sw_order'], $oStartus, $orderId);
                }

                if ($oStartus === 'in_progress') {
                    if ($paymentState === 'finished' || $paymentState === 'sending') {
                        if ($this->validatePayment((string) ($response['purchase_id'] ?? ''), $paymentState)) {
                            $this->transactionStateHandler->paid($requestqueryaRRAY['_sw_order'], $context);
                        }

                        return $this->finalizeSuccessResponse($isRest, $requestqueryaRRAY['_sw_order'], $paymentState, $orderId);
                    }

                    if ($paymentState === 'partially_paid') {
                        $this->transactionStateHandler->payPartially($requestqueryaRRAY['_sw_order'], $context);

                        return $this->finalizeSuccessResponse($isRest, $requestqueryaRRAY['_sw_order'], $paymentState, $orderId);
                    }

                    if ($paymentState === 'failed' || $paymentState === 'refunded' || $paymentState === 'expired') {
                        $this->transactionStateHandler->fail($requestqueryaRRAY['_sw_order'], $context);

                        return $this->finalizeSuccessResponse($isRest, $requestqueryaRRAY['_sw_order'], $paymentState, $orderId);
                    }
                }

                return $this->emptyFinalizeResponse($isRest, array_replace($requestqueryaRRAY, ['paymentStatus' => $oStartus]));
            }

            return $this->emptyFinalizeResponse($isRest, $requestqueryaRRAY);
        } catch (\Exception $e) {
            $this->debugLog->send('Error', 'An error occurred during reading the gateway responce' . PHP_EOL . $e->getMessage());

            return $this->emptyFinalizeResponse($isRest);
        }
    }

    /**
     * @Route(
     *     "/checkout/jovepay/callback",
     *     name="frontend.checkout.jovepay.callback",
     *     methods={"GET", "POST"},
     *     defaults={"auth_required"=false, "csrf_protected"=false},
     *     options={"seo"=false}
     * )
     */
    public function finalizeTransaction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        return $this->_finalizeTransaction($request, $salesChannelContext, true);
    }
}
