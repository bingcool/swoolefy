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

use Throwable;

/**
 * 交给成功/失败钩子的完整上下文。对应 gofy claim.Result。
 *
 * 同时带业务 taskId（来自 handler）与 claim 侧的行号/认领次数，
 * 让钩子能把一次结果与业务记录和表行都对应起来。
 */
final class ClaimResult
{
    public function __construct(
        /** 业务标识，由 handler 从 payload 解出 */
        public readonly int|string|null $taskId,
        /** claim 表主键 */
        public readonly int $rowId,
        public readonly string $job,
        /** 本次 handler 对应的第几次认领 */
        public readonly int $attempts,
        public readonly ?string $data,
        /** handler 的错误；成功路径为 null */
        public readonly ?Throwable $error,
        /** 失败且行被置为 dead：次数耗尽，或 rerun_allowed = 0（只允许执行一次） */
        public readonly bool $dead = false,
        /** 收尾时发现行已被接管，什么都没写；副作用可能已在别处发生 */
        public readonly bool $leaseLost = false,
        /** handler 耗时（秒），不含收尾写库 */
        public readonly float $elapsedSeconds = 0.0,
    ) {
    }
}
