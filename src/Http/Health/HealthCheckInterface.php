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

/**
 * 单项健康检查契约（Redis / DB / process / 自定义）。
 *
 * ## 设计要点
 * - 检查应**快速失败**（短超时、少往返），避免 readiness 拖死 kubelet 探针周期。
 * - 实现内部应自行 catch 异常，转为 {@see HealthCheckResult}（ok=false），
 *   不要把连接异常抛出到 Controller，否则整请求可能变成 500 而非可预期的 503。
 *
 * @see Check\RedisHealthCheck
 * @see Check\DatabaseHealthCheck
 * @see Check\ProcessHealthCheck
 * @see CheckFactory
 */
interface HealthCheckInterface
{
    /**
     * 检查项稳定名称，出现在 JSON `checks[].name`。
     * 建议小写短标识（如 `redis`、`database`），便于监控聚合。
     */
    public function name(): string;

    /**
     * 执行一次检查并返回结构化结果。
     *
     * 成功：ok=true；失败：ok=false + message 说明原因。
     * 耗时等诊断信息可放在 Result.meta（生产可用 Config 关闭 details）。
     */
    public function check(): HealthCheckResult;
}
