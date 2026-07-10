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

namespace Swoolefy\Support\CapabilityCenter\Resolver;

use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;

/**
 * Capability 解析结果。
 *
 * 由 Resolver 输出，供 CapabilityCenter 遍历 materialize。
 * 包含排序分数和命中阶段，便于 debug 日志观察筛选过程。
 */
final class ResolvedCapability
{
    /**
     * @param CapabilityDescriptor $descriptor 命中的元数据
     * @param float                $score      排序分数（pinned 固定 1000.0）
     * @param string               $stage      命中阶段：pinned | tag
     */
    public function __construct(
        public readonly CapabilityDescriptor $descriptor,
        public readonly float $score,
        public readonly string $stage,
    ) {
    }
}
