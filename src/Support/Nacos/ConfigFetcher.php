<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;

/**
 * Nacos 配置中心：拉取 / 发布配置。
 */
final class ConfigFetcher
{
    public function __construct(
        private readonly NacosConfig $nacosConfig,
        private readonly ServiceConfig $serviceConfig,
    ) {
    }

    public static function create(): self
    {
        return new self(NacosConfig::load(), ServiceConfig::load());
    }

    public function get(?string $dataId = null, ?string $group = null, ?string $tenant = null): string
    {
        $client = $this->nacosConfig->createClient();

        return $client->config->get(
            $dataId ?? $this->serviceConfig->dataId,
            $group ?? $this->serviceConfig->group,
            $tenant ?? $this->serviceConfig->tenant,
        );
    }

    public function set(
        string $content,
        ?string $dataId = null,
        ?string $group = null,
        ?string $tenant = null,
        string $type = '',
    ): void {
        $client = $this->nacosConfig->createClient();
        $dataId ??= $this->serviceConfig->dataId;
        $group ??= $this->serviceConfig->group;
        $tenant ??= $this->serviceConfig->tenant;

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
