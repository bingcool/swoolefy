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

namespace Swoolefy\Support\Workflow\Engine;

/** 空实现事件分发器（Phase 1 默认，不对外广播）。 */
final class NullWorkflowEventDispatcher implements WorkflowEventDispatcherInterface
{
    /** {@inheritdoc} 无操作。 */
    public function publish(string $event, array $payload): void
    {
    }
}
