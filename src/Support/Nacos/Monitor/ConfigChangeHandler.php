<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Library\Nacos\Client;
use Swoolefy\Support\Nacos\ConfigFetcher;
use Swoolefy\Support\Nacos\ConfigFileWriter;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Util\Log;

/**
 * 配置变更：拉取 Nacos → 写 .env → restart 整个服务。
 */
final class ConfigChangeHandler
{
    private readonly ConfigFileWriter $configFileWriter;
    private readonly ServiceRestarter $restarter;
    private readonly ConfigFetcher $configFetcher;

    public function __construct(
        private readonly MonitorConfig $config,
        private readonly Log $logger,
        ?ConfigFileWriter $configFileWriter = null,
        ?ServiceRestarter $restarter = null,
        ?ConfigFetcher $configFetcher = null,
    ) {
        $this->configFileWriter = $configFileWriter ?? new ConfigFileWriter($logger);
        $this->restarter = $restarter ?? new ServiceRestarter($logger);
        $this->configFetcher = $configFetcher ?? new ConfigFetcher($config->nacos, $logger);
    }

    public function handle(): void
    {
        $lockFp = fopen($this->config->lockFile, 'c');
        if (false === $lockFp) {
            $this->logger->error('cannot open lock file: ' . $this->config->lockFile);
            return;
        }

        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            $this->logger->info('restart already in progress, skip');
            fclose($lockFp);
            return;
        }

        try {
            $this->logger->info(sprintf(
                'config changed, dataId=%s, group=%s',
                $this->config->nacos->dataId,
                $this->config->nacos->group,
            ));

            $content = $this->configFetcher->get();

            $this->configFileWriter->write($this->config->envFile, $content);
            $delaySeconds = new \Random\Randomizer()->getInt(1, 5);
            sleep($delaySeconds);

            $this->restarter->restart();

            $this->logger->info('config change handled: env updated and restart triggered');
        } catch (\Throwable $e) {
            $this->logger->error('handle config change failed: ' . $e->getMessage());
            throw $e;
        } finally {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    public static function createClient(NacosConfig $config, Log $logger): Client
    {
        return $config->createClient($logger);
    }
}
