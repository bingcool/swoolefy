<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Util\Log;

/**
 * Nacos 配置中心：拉取 / 发布配置。
 */
final class ConfigFetcher
{
    public function __construct(
        private readonly NacosConfig $config,
        private readonly ?Log $logger = null,
    ) {
    }

    public function get(?string $dataId = null, ?string $group = null, ?string $tenant = null): string
    {
        $client = $this->config->createClient($this->logger);

        return $client->config->get(
            $dataId ?? $this->config->dataId,
            $group ?? $this->config->group,
            $tenant ?? $this->config->tenant,
        );
    }

    public function set(
        string $content,
        ?string $dataId = null,
        ?string $group = null,
        ?string $tenant = null,
        string $type = '',
    ): void {
        $client = $this->config->createClient($this->logger);
        $dataId ??= $this->config->dataId;
        $group ??= $this->config->group;
        $tenant ??= $this->config->tenant;

        $ok = $client->config->set($dataId, $group, $content, $tenant, $type);
        if (!$ok) {
            throw NacosMonitorException::throw(sprintf(
                'Nacos config set failed: dataId=%s, group=%s',
                $dataId,
                $group,
            ));
        }
    }
}
