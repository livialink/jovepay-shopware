<?php declare(strict_types=1);

namespace JovepayPlugin\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Shopware < 6.4.11 expects `_routeScope` to be a RouteScope annotation object
 * (RouteScopeCheckTrait calls getScopes()). YAML defaults are arrays (correct for
 * 6.4.11+). Convert before SalesChannel context resolve (CONTROLLER -10).
 *
 * RouteScope extends Sensio ConfigurationAnnotation which requires
 * __construct(array $values) — never instantiate with zero args.
 */
class RouteScopeCompatSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'frontend.checkout.jovepay.';
    private const PATH_PREFIX = '/checkout/jovepay';

    public static function getSubscribedEvents(): array
    {
        return [
            // After RouterListener (32)
            KernelEvents::REQUEST => ['ensureRouteScopeOnRequest', 31],
            // Before ContextResolverListener (-10) and RouteScopeListener (-20)
            KernelEvents::CONTROLLER => ['ensureRouteScopeOnController', 0],
        ];
    }

    public function ensureRouteScopeOnRequest(RequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $this->ensureRouteScope($event->getRequest());
    }

    public function ensureRouteScopeOnController(ControllerEvent $event): void
    {
        $this->ensureRouteScope($event->getRequest());
    }

    private function ensureRouteScope(Request $request): void
    {
        if (!$this->isJovepayRequest($request)) {
            return;
        }

        $annotationClass = 'Shopware\\Core\\Framework\\Routing\\Annotation\\RouteScope';
        $scopes = $this->resolveScopes($request);

        // Shopware 6.4.11+ / 6.5+: array is the supported form (annotation may be gone).
        if (!\class_exists($annotationClass)) {
            $request->attributes->set('_routeScope', $scopes);

            return;
        }

        $current = $request->attributes->get('_routeScope');
        if (\is_object($current) && \method_exists($current, 'getScopes')) {
            return;
        }

        // ConfigurationAnnotation::__construct(array $values) is required.
        /** @var object $routeScope */
        $routeScope = new $annotationClass(['scopes' => $scopes]);

        $request->attributes->set('_routeScope', $routeScope);

        if (\class_exists('Shopware\\Core\\PlatformRequest')
            && \defined('Shopware\\Core\\PlatformRequest::ATTRIBUTE_ROUTE_SCOPE')
        ) {
            $key = \constant('Shopware\\Core\\PlatformRequest::ATTRIBUTE_ROUTE_SCOPE');
            $request->attributes->set($key, $routeScope);
        }
    }

    private function isJovepayRequest(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');
        if (\is_string($routeName) && \strpos($routeName, self::ROUTE_PREFIX) === 0) {
            return true;
        }

        $path = $request->getPathInfo();

        return \strpos($path, self::PATH_PREFIX) === 0;
    }

    /**
     * @return list<string>
     */
    private function resolveScopes(Request $request): array
    {
        $current = $request->attributes->get('_routeScope');
        if (\is_array($current) && $current !== []) {
            return \array_values($current);
        }

        if (\is_object($current) && \method_exists($current, 'getScopes')) {
            $scopes = $current->getScopes();
            if (\is_array($scopes) && $scopes !== []) {
                return \array_values($scopes);
            }
        }

        return ['storefront'];
    }

    private function isMainRequest(RequestEvent $event): bool
    {
        if (\method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        if (\method_exists($event, 'isMasterRequest')) {
            return $event->isMasterRequest();
        }

        return true;
    }
}
