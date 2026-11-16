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

namespace Swoolefy\Support\Claim;

use Closure;

/**
 * ClaimRunner 的全部参数。对应 gofy claim.RunnerConfig。
 *
 * Config 部分提供队列参数与队列级钩子；下面几项是 Runner 自己的。
 */
final class RunnerConfig
{
    /**
     * @param ClaimConfig $config 队列参数
     * @param Closure|null $handler fn (?string $payload): int|string|null
     *        处理一条已认领的行。返回的 taskId 只用于结果钩子/日志，Runner 不解释它。
     *        抛异常即视为失败。空闲一轮会以 null payload 调用一次，返回值被忽略；
     *        此时没有行可以收尾，不会写任何列。
     *
     *        必须幂等：handler 已产生副作用、但 succeed 还没提交时进程崩溃，租约到期后
     *        别的副本会回收并重跑这一行——这是先后重复，不是同时重复。认领与围栏挡不住，
     *        因为数据库无从知道外部副作用是否已经发生。用 payload 里的业务单号做唯一键或
     *         upsert，或用 ClaimResult::$rowId 做幂等台账。
     * @param float $handlerTimeoutSeconds 单次 handler 的协作式截止时间，秒，<= 0 用 5 秒。
     *        它不是强杀：只能中断可协作的 Swoole 协程调用，阻塞型扩展要自己设网络超时。
     *        必须小于 leaseSeconds（构造时强制），否则慢 handler 还没结束行就可能被接管。
     * @param Closure|null $onSuccess fn (ClaimResult $result): void，Succeed 落库后触发
     * @param Closure|null $onFailure fn (ClaimResult $result): void，Fail 落库后触发；
     *        租约丢失与 handler 报错也会触发，通过 ClaimResult 的标志区分
     */
    public function __construct(
        public readonly ClaimConfig $config,
        public readonly ?Closure $handler = null,
        public readonly float $handlerTimeoutSeconds = 0.0,
        public readonly ?Closure $onSuccess = null,
        public readonly ?Closure $onFailure = null,
    ) {
    }
}
