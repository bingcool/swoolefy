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

namespace Swoolefy\Http\Health;

use Swoolefy\Http\Route;

/**
 * 注册 K8s 探针路由（无 group 前缀、无中间件）。
 *
 * ## 用法
 * ```php
 * \Swoolefy\Http\Health\HealthRoutes::register();
 * ```
 * 或 require stub：`src/Stubs/health.router.stub.php` /
 * `Test/Router/Common/Health.php`（框架会扫描 Router 下全部 .php）。
 *
 * ## 为何无中间件
 * 鉴权会导致探针 401；限流可能导致探针被 429，进而误杀/摘流。
 * 路由项仅含 `dispatch_route`，不经过 group middleware。
 */
final class HealthRoutes
{
    /** 进程内幂等标志，防止 Router 扫描多次 include 时重复注册。 */
    private static bool $registered = false;

    /**
     * 按 Config 注册全部 liveness / readiness 路径（含 aliases）。
     *
     * 重复调用安全：第二次起直接 return。
     * `enabled=false` 时不注册任何路径（请求将 404，请在生产保持 enabled）。
     */
    public static function register(?HealthConfig $config = null): void
    {
        if (self::$registered) {
            return;
        }

        $config ??= HealthConfig::load();
        if (!$config->enabled()) {
            self::$registered = true;

            return;
        }

        // 先校验再置位：未知 type 抛异常时允许修复配置后重试注册
        $timeout = $config->checkTimeoutSeconds();
        CheckFactory::assertDefsValid($config->livenessCheckDefs(), $timeout);
        CheckFactory::assertDefsValid($config->readinessCheckDefs(), $timeout);

        self::$registered = true;

        // 每个 path 独立 Route::get；别名与主路径指向同一 Controller action
        foreach ($config->livenessPaths() as $path) {
            Route::get($path, [
                'dispatch_route' => [HealthController::class, 'live'],
            ]);
        }

        foreach ($config->readinessPaths() as $path) {
            Route::get($path, [
                'dispatch_route' => [HealthController::class, 'ready'],
            ]);
        }
    }

    /**
     * 单测重置幂等标志（生产勿调用）。
     *
     * @internal
     */
    public static function resetRegisteredFlag(): void
    {
        self::$registered = false;
    }
}
