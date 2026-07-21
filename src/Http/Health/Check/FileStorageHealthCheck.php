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

namespace Swoolefy\Http\Health\Check;

use Swoolefy\Core\Application;
use Swoolefy\Http\Health\HealthCheckInterface;
use Swoolefy\Http\Health\HealthCheckResult;
use Swoolefy\Library\FileStorageSystem\FileDisk;
use Swoolefy\Library\FileStorageSystem\FileStorageManager;
use Throwable;

/**
 * FileStorageSystem 连通性检查：对指定 disk 做轻量 put → exists → delete。
 *
 * ## 支持的盘（Config/file_storage_system.php 中的 provider）
 * | driver | 说明 |
 * |--------|------|
 * | local | 本机目录可写 |
 * | aws_s3 | Amazon S3 / 兼容 Endpoint |
 * | aliyun_oss | 阿里云 OSS |
 * | tengxun_cos | 腾讯云 COS |
 * | fake | 内存盘（单测） |
 *
 * 推荐挂在 **readiness**；不要挂 liveness（云抖动会杀 Pod）。
 *
 * @see \Swoolefy\Library\FileStorageSystem\FileStorageManager
 * @see Config/file_storage_system.php
 * @see Config/component/file_storage.php
 */
final class FileStorageHealthCheck implements HealthCheckInterface
{
    /**
     * @param string      $component Application 组件名（默认 `file_storage`）
     * @param string      $name      JSON checks[].name
     * @param string|null $disk      provider 键名；null = default_provider
     * @param string      $probePath 探针对象 path（写后即删）
     */
    public function __construct(
        private readonly string $component = 'file_storage',
        private readonly string $name = 'file_storage',
        private readonly ?string $disk = null,
        private readonly string $probePath = '.swoolefy/health-probe',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * putObject 探针内容 → fileExists → delete；异常 → ok=false。
     */
    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $mgr = $this->resolveManager();
            $disk = $this->disk !== null && $this->disk !== ''
                ? $mgr->disk($this->disk)
                : $mgr->disk();

            $payload = 'ok:' . (string) microtime(true);
            $disk->putObject($this->probePath, $payload, [
                'mime' => 'text/plain',
            ]);
            if (!$disk->fileExists($this->probePath)) {
                return new HealthCheckResult(
                    name: $this->name(),
                    ok: false,
                    message: 'probe object not found after put',
                    meta: $this->meta($disk, $started),
                );
            }
            $disk->delete($this->probePath);

            return new HealthCheckResult(
                name: $this->name(),
                ok: true,
                message: 'put/exists/delete ok',
                meta: $this->meta($disk, $started),
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                name: $this->name(),
                ok: false,
                message: $e->getMessage(),
                meta: [
                    'component' => $this->component,
                    'disk' => $this->disk,
                    'probe_path' => $this->probePath,
                    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                ],
            );
        }
    }

    /**
     * @return array{component: string, disk: string|null, driver: string, probe_path: string, latency_ms: float}
     */
    private function meta(FileDisk $disk, float $started): array
    {
        return [
            'component' => $this->component,
            'disk' => $this->disk,
            'driver' => $disk->driver(),
            'probe_path' => $this->probePath,
            'latency_ms' => round((microtime(true) - $started) * 1000, 2),
        ];
    }

    /**
     * @throws \RuntimeException
     */
    private function resolveManager(): FileStorageManager
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get($this->component);
        if (is_object($component) && method_exists($component, 'getObject')) {
            $mgr = $component->getObject();
        } else {
            $mgr = $component;
        }

        if (!$mgr instanceof FileStorageManager) {
            throw new \RuntimeException(sprintf(
                'health file_storage component "%s" is not a FileStorageManager',
                $this->component,
            ));
        }

        return $mgr;
    }
}
