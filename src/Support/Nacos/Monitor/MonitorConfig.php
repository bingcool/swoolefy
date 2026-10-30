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

namespace Swoolefy\Support\Nacos\Monitor;

use Swoolefy\Core\SystemEnv;
use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\NacosConst;
use Swoolefy\Support\Nacos\ServiceConfig;

/**
 * Monitor 配置：Nacos 服务器 + application.yaml → nacos.monitor_config_change。
 */
final class MonitorConfig
{
    public function __construct(
        public readonly NacosConfig $nacosConfig,
        public readonly ServiceConfig $serviceConfig,
        public readonly string $envFile,
        public readonly string $lockFile,
        public readonly int $listenerTimeoutMs,
        public readonly int $failedWaitMs,
        public readonly bool $k8sMode = false,
    ) {
    }

    public static function load(): self
    {
        $appPath = ApplicationConfig::resolveAppPath();
        $nacosConfig = NacosConfig::load();
        $monitor = ApplicationConfig::load()->nacosSection('monitor_config_change');
        $appName = defined('APP_NAME') ? (string) APP_NAME : basename($appPath);

        return new self(
            nacosConfig: $nacosConfig,
            serviceConfig: ServiceConfig::load(),
            envFile: ApplicationConfig::pickString($monitor, 'env_file', NacosConst::ENV_ENV_FILE, $appPath . '/.env'),
            lockFile: ApplicationConfig::pickString($monitor, 'lock_file', NacosConst::ENV_RELOAD_LOCK, '/tmp/swoolefy_' . strtolower($appName) . '_nacos_restart.lock'),
            listenerTimeoutMs: ApplicationConfig::pickInt($monitor, 'listener_timeout_ms', NacosConst::ENV_LISTENER_TIMEOUT_MS, 30_000),
            failedWaitMs: ApplicationConfig::pickInt($monitor, 'failed_wait_ms', NacosConst::ENV_LISTENER_FAILED_MS, 3_000),
            k8sMode: self::detectK8sMode(),
        );
    }

    /**
     * k8s 下 master 退出会连带容器终止，重启由 kubelet 按 restartPolicy 完成。
     * 除显式 `--fromk8s` 外，也识别 Pod 内自动注入的 KUBERNETES_SERVICE_HOST，避免依赖启动命令加参数。
     */
    private static function detectK8sMode(): bool
    {
        if (SystemEnv::isFromK8s()) {
            return true;
        }

        return '' !== trim((string) env('KUBERNETES_SERVICE_HOST',''));
    }
}
