<?php declare(strict_types=1);

namespace JovepayPlugin\Util;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class DebugLog
{
    private SystemConfigService $systemConfigService;

    /** @var object|null */
    private $restClient = null;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @return bool
     */
    public function send(string $origin, $data)
    {
        if (!(bool) $this->systemConfigService->get('JovepayPlugin.config.DebugPost')) {
            return false;
        }

        $url = (string) $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL');
        if ($url === '') {
            return false;
        }

        $client = $this->getRestClient();
        if ($client === null) {
            return false;
        }

        try {
            $requestClass = 'GuzzleHttp\\Psr7\\Request';
            $request = new $requestClass(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
                json_encode(['_DebuglogOrigin' => $origin, 'data' => json_encode($data)])
            );
            $client->send($request);
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function forwardCopy(string $origin, $request)
    {
        if (!(bool) $this->systemConfigService->get('JovepayPlugin.config.DebugPost')) {
            return false;
        }

        $url = (string) $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL');
        if ($url === '') {
            return false;
        }

        $client = $this->getRestClient();
        if ($client === null) {
            return false;
        }

        try {
            $queryBag = $request->query;
            $requestBag = $request->request;
            $requestClass = 'GuzzleHttp\\Psr7\\Request';
            $requestGuzz = new $requestClass(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
                json_encode(array_replace(
                    ['_DebuglogOrigin' => $origin],
                    ['getperm' => $queryBag->all(), 'postperm' => $requestBag->all()],
                    ['Body' => $request->getContent(), 'server' => $request->server->all()]
                ))
            );
            $client->send($requestGuzz);
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * @return object|null
     */
    private function getRestClient()
    {
        if ($this->restClient !== null) {
            return $this->restClient;
        }

        if (!class_exists('GuzzleHttp\\Client')) {
            return null;
        }

        $clientClass = 'GuzzleHttp\\Client';
        $this->restClient = new $clientClass();

        return $this->restClient;
    }
}
