<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Nacos;

use Swoolefy\Exception\NacosMonitorException;
use Swoolefy\Support\ApplicationConfig;

/**
 * Nacos 配置中心项（application.yaml → nacos.service_config）。
 *
 * 命名空间 tenant 优先级：nacos.yaml `namespace` > application.yaml `tenant` > 环境变量 NACOS_TENANT > public（空）。
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

        $nacosConfig = NacosConfig::load();

        return new self(
            dataId: $dataId,
            group: $group,
            tenant: self::resolveTenant($section, $nacosConfig),
        );
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function resolveTenant(array $section, NacosConfig $nacosConfig): string
    {
        if ('' !== $nacosConfig->namespace) {
            return $nacosConfig->namespace;
        }

        return NacosConfig::normalizeNamespace(
            ApplicationConfig::pickString($section, 'tenant', NacosConst::ENV_NACOS_TENANT, ''),
        );
    }
}
