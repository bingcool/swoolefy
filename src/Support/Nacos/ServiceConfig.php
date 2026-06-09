<?php

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Support\ApplicationConfig;

/**
 * Nacos 配置中心项（application.yaml → nacos.service_config）。
 */
final class ServiceConfig
{
    public function __construct(
        public readonly string $dataId,
        public readonly string $group,
        public readonly string $tenant,
    ) {
    }

    public static function load(): self
    {
        $section = ApplicationConfig::load()->nacosSection('service_config');

        $dataId = trim((string) ($section['data_id'] ?? ''));
        if ('' === $dataId) {
            throw NacosMonitorException::throw('nacos.service_config.data_id is required');
        }

        $group = trim((string) ($section['group'] ?? ''));
        if ('' === $group) {
            throw NacosMonitorException::throw('nacos.service_config.group is required');
        }

        return new self(
            dataId: $dataId,
            group: $group,
            tenant: ApplicationConfig::pickString($section, 'tenant', 'NACOS_TENANT', ''),
        );
    }
}
