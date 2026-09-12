<?php

declare(strict_types=1);

namespace JovepayPlugin\Util;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;



class DebugLog
{

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var Client
     */
    private $restClient;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
        $this->restClient = new Client();
    }


    /**
     * @return Bool
     */
    public function send(string $origin, $data)
    {
        if (((bool) $this->systemConfigService->get('JovepayPlugin.config.DebugPost')) && $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL')) {
            $url = $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL');

            $request = new Request(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
                json_encode(['_DebuglogOrigin' => $origin, 'data' => json_encode($data)])
            );

            $response = $this->restClient->send($request);
        }
        return false;
    }

    /**
     * @return Bool
     */
    public function forwardCopy(string $origin, $request)
    {
        if (((bool) $this->systemConfigService->get('JovepayPlugin.config.DebugPost')) && $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL')) {
            $queryBag = $request->query;
            $requestBag = $request->request;

            $requestGuzz = new Request(
                'POST',
                $this->systemConfigService->get('JovepayPlugin.config.DebugPostURL'),
                ['Content-Type' => 'application/json'],
                json_encode(array_replace(['_DebuglogOrigin' => $origin], ['getperm' => $queryBag->all(), 'postperm' => $requestBag->all()], ['Body' => $request->getContent(), 'server' => $request->server->all()]))
            );

            $response = $this->restClient->send($requestGuzz);
        }
        return false;
    }
}
