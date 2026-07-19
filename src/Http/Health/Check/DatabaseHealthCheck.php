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
use Throwable;

/**
 * 数据库连通性：对组件执行 `SELECT 1`。
 *
 * ## 兼容策略（按优先级尝试）
 * 1. `createCommand('SELECT 1')->queryScalar()`（Library Mysql / PDOConnection）
 * 2. `createCommand(...)->queryAll()`
 * 3. `query('SELECT 1', [])`（ConnectionInterface）
 * 4. 原生 `PDO::query('SELECT 1')`
 *
 * 推荐挂在 **readiness**。连接池耗尽时本检查也会失败，符合「暂不可接流量」语义。
 */
final class DatabaseHealthCheck implements HealthCheckInterface
{
    /**
     * @param string $component Application 组件名（默认 `db`）
     * @param string $name      JSON checks[].name
     */
    public function __construct(
        private readonly string $component = 'db',
        private readonly string $name = 'database',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * 执行 SELECT 1；异常 → ok=false（不抛出）。
     */
    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $db = $this->resolveDb();
            $this->ping($db);

            return new HealthCheckResult(
                name: $this->name(),
                ok: true,
                message: 'select 1 ok',
                meta: [
                    'component' => $this->component,
                    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                ],
            );
        } catch (Throwable $e) {
            return new HealthCheckResult(
                name: $this->name(),
                ok: false,
                message: $e->getMessage(),
                meta: [
                    'component' => $this->component,
                    'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                ],
            );
        }
    }

    /**
     * 取出 DB 对象（同样兼容 getObject() 包装）。
     *
     * @throws \RuntimeException
     */
    private function resolveDb(): object
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get($this->component);
        if (is_object($component) && method_exists($component, 'getObject')) {
            $db = $component->getObject();
        } else {
            $db = $component;
        }

        if (!is_object($db)) {
            throw new \RuntimeException(sprintf(
                'health database component "%s" is not an object',
                $this->component,
            ));
        }

        return $db;
    }

    /**
     * 多形态 ping，避免绑定单一 ORM API。
     *
     * @throws \RuntimeException 无法识别的驱动类型
     */
    private function ping(object $db): void
    {
        // Library 常见：createCommand 返回 Command，再 queryScalar
        if (method_exists($db, 'createCommand')) {
            $cmd = $db->createCommand('SELECT 1');
            if (is_object($cmd) && method_exists($cmd, 'queryScalar')) {
                $cmd->queryScalar();

                return;
            }
            if (is_object($cmd) && method_exists($cmd, 'queryAll')) {
                $cmd->queryAll();

                return;
            }
        }

        if (method_exists($db, 'query')) {
            $db->query('SELECT 1', []);

            return;
        }

        if ($db instanceof \PDO) {
            $db->query('SELECT 1');

            return;
        }

        throw new \RuntimeException(sprintf(
            'health database component "%s" (%s) does not support SELECT 1',
            $this->component,
            $db::class,
        ));
    }
}
