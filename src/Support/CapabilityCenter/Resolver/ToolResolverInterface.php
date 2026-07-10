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

/**
 * Tool 解析器契约。
 *
 * 输入请求态 ToolResolveContext，输出带分数的 ResolvedCapability 列表。
 * 不负责 materialize，只负责筛选与排序。
 */
interface ToolResolverInterface
{
    /**
     * 解析出本轮应注入的 Capability 候选。
     *
     * @return list<ResolvedCapability> pinned 在前，tag 匹配在后
     */
    public function resolve(ToolResolveContext $context): array;
}
