<?php declare(strict_types=1);

namespace JovepayPlugin\Util;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Creates a rotating file logger under var/log without depending on
 * Shopware\Core\Framework\Log\LoggerFactory (removed / not a service in 6.7).
 */
class PluginLoggerFactory
{
    public static function create(string $logsDir, string $channel = 'JovepayPlugin'): LoggerInterface
    {
        $logger = new Logger($channel);
        $logger->pushHandler(new RotatingFileHandler(
            rtrim($logsDir, '/') . '/' . $channel . '.log',
            14
        ));

        return $logger;
    }
}
