<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Library\Nacos\Provider\Config\ConfigListener;
use Swoolefy\Library\Nacos\Provider\Config\Model\ListenerConfig;
use Swoolefy\Support\Nacos\NacosLogger;
use Swoolefy\Util\Log;

/**
 * Nacos 长轮询监听进程逻辑。
 */
final class ConfigWatcher
{
    private readonly Log $logger;

    public function __construct(
        private readonly MonitorConfig $config,
        private readonly ConfigChangeHandler $changeHandler,
    ) {
        $this->logger = NacosLogger::get();
    }

    public function run(): void
    {
        $this->logger->info(sprintf(
            'watcher started, pid=%d, dataId=%s, group=%s, env=%s, yaml=%s',
            getmypid(),
            $this->config->serviceConfig->dataId,
            $this->config->serviceConfig->group,
            $this->config->envFile,
            $this->config->nacosConfig->nacosFilePath,
        ));

        $client = ConfigChangeHandler::createClient($this->config->nacosConfig);

        $listenerConfig = new ListenerConfig([
            'timeout' => $this->config->listenerTimeoutMs,
            'failedWaitTime' => $this->config->failedWaitMs,
        ]);

        $listener = $client->config->getConfigListener($listenerConfig);
        $armed = false;

        $listener->addListener(
            $this->config->serviceConfig->dataId,
            $this->config->serviceConfig->group,
            $this->config->serviceConfig->tenant,
            function (ConfigListener $configListener, string $dataId, string $group, string $tenant) use (&$armed): void {
                unset($configListener, $dataId, $group, $tenant);
                if (!$armed) {
                    return;
                }
                try {
                    $this->changeHandler->handle();
                } catch (\Throwable $e) {
                    $this->logger->error('handle change error: ' . $e->getMessage());
                }
            },
        );

        try {
            $listener->pull(false);
        } catch (\Throwable $e) {
            $this->logger->error('initial pull warning: ' . $e->getMessage());
        }

        $armed = true;

        while (true) {
            try {
                if (!$listener->polling($this->config->listenerTimeoutMs)) {
                    usleep($this->config->failedWaitMs * 1000);
                }
            } catch (\Throwable $e) {
                $this->logger->error('polling error: ' . $e->getMessage());
                usleep($this->config->failedWaitMs * 1000);
            }
        }
    }
}
