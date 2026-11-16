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

/**
 * 一行被认领的任务。
 *
 * owner + attempts 是围栏组合：succeed()/fail() 必须用 Claim 原样返回的这两个值。
 * attempts 在认领时 +1，因此同一个 owner 重新认领同一行后，旧 task 的收尾写不进去。
 * 对应 gofy claim.Task。
 */
final class ClaimTask
{
    /**
     * @param int $id claim 表主键
     * @param int $attempts 本次认领后的次数（已经 +1）
     * @param string|null $payload 原始载荷；JSON 列的字符串原样交给业务解码
     * @param string $job 任务类型
     * @param string $owner 本次认领者
     */
    public function __construct(
        public readonly int $id,
        public readonly int $attempts,
        public readonly ?string $payload = null,
        public readonly string $job = '',
        public readonly string $owner = '',
    ) {
    }
}
