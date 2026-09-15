<?php declare(strict_types=1);

namespace JovepayPlugin\Util;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Payment logger that writes only into Shopware's var/log directory.
 */
class PaymentLogger
{
    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(SystemConfigService $systemConfigService, LoggerInterface $logger)
    {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * @param mixed $data
     */
    public function info(string $message, $data = null): void
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $this->logger->info($message, $this->normalizeContext($data));
    }

    /**
     * @param mixed $data
     */
    public function error(string $message, $data = null): void
    {
        $this->logger->error($message, $this->normalizeContext($data));
    }

    /**
     * @param mixed $request
     */
    public function logRequest(string $message, $request): void
    {
        if (!$this->isDebugEnabled() || !\is_object($request)) {
            return;
        }

        $context = [];
        if (method_exists($request, 'query') || (isset($request->query) && \is_object($request->query))) {
            $context['query'] = $request->query->all();
        }
        if (method_exists($request, 'request') || (isset($request->request) && \is_object($request->request))) {
            $context['request'] = $request->request->all();
        }
        if (method_exists($request, 'getContent')) {
            $context['body'] = $request->getContent();
        }

        $this->logger->info($message, $context);
    }

    private function isDebugEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('JovepayPlugin.config.debugLogging');
    }

    /**
     * @param mixed $data
     *
     * @return array<string, mixed>
     */
    private function normalizeContext($data): array
    {
        if ($data === null) {
            return [];
        }

        if (\is_array($data)) {
            return $data;
        }

        if (\is_scalar($data)) {
            return ['value' => $data];
        }

        return ['value' => (string) json_encode($data)];
    }
}
