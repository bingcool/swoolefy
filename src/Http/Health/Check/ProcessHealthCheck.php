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

use Swoolefy\Http\Health\HealthCheckInterface;
use Swoolefy\Http\Health\HealthCheckResult;

/**
 * 进程存活检查：无外部 I/O。
 *
 * Worker 能执行到 {@see check()} 即视为 up——适合作为 **默认 liveness**：
 * 不因 Redis/DB 抖动触发 kubelet 重启。
 */
final class ProcessHealthCheck implements HealthCheckInterface
{
    /** 固定名 `process`，与 Config type=process / JSON checks[].name 对齐。 */
    public function name(): string
    {
        return 'process';
    }

    /**
     * 恒成功；meta 附带 pid / php 版本便于排障（生产可关 details）。
     */
    public function check(): HealthCheckResult
    {
        return new HealthCheckResult(
            name: $this->name(),
            ok: true,
            message: 'worker process is serving',
            meta: [
                'pid' => getmypid(),
                'php' => PHP_VERSION,
            ],
        );
    }
}
