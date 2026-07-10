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

namespace Swoolefy\Support\Workflow\Plugin\Builtin;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\NodeStatus;
use Swoolefy\Support\Workflow\Engine\RetryPolicy;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 重试插件 —— 在 RETRY 结果上附加策略元数据。
 * 实际退避重试循环在 WorkflowEngine::executeNode() 中执行。
 */
final class RetryPlugin implements WorkflowPluginInterface
{
    public function __construct(
        private readonly RetryPolicy $defaultPolicy = new RetryPolicy(),
    ) {
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'retry';
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onNodeAfter(function (
            RunContext $ctx,
            NodeInterface $node,
            WorkflowState $state,
            NodeExecutionResult $result,
        ): void {
            if ($result->status !== NodeStatus::RETRY) {
                return;
            }

            $policy = $result->retry ?? $this->defaultPolicy;
            $result->metrics['retryPolicy'] = [
                'maxAttempts' => $policy->maxAttempts,
                'delayMs' => $policy->delayMs,
            ];
        });
    }
}
