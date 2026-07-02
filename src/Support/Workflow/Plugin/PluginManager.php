<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin;

use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Node\NodeInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * 插件钩子分发器 —— 在引擎生命周期边界触发横切逻辑。
 *
 * Plugin 与 EventBus 区别：
 *   Plugin   — 引擎内部（指标、追踪、限流）
 *   EventBus — 对外 SSE/WebSocket（token 流、edge.route）
 *
 * @see swoolefyAI.md §3.5
 */
final class PluginManager
{
    /** @var array<string, WorkflowPluginInterface> */
    private array $plugins = [];

    private PluginRegistry $registry;

    /** @param list<WorkflowPluginInterface> $plugins 初始插件列表 */
    public function __construct(array $plugins = [])
    {
        $this->registry = new PluginRegistry();
        foreach ($plugins as $plugin) {
            $this->add($plugin);
        }
    }

    /** 注册并激活插件。 */
    public function add(WorkflowPluginInterface $plugin): void
    {
        $this->plugins[$plugin->name()] = $plugin;
        $plugin->register($this->registry);
    }

    /** 获取钩子注册表。 */
    public function registry(): PluginRegistry
    {
        return $this->registry;
    }

    /** Run 开始时触发。 */
    public function fireRunStart(WorkflowRun $run, array $input): void
    {
        foreach ($this->registry->hooks('run.start') as $hook) {
            $hook($run, $input);
        }
    }

    /** Run 完成或失败时触发。 */
    public function fireRunComplete(WorkflowRun $run): void
    {
        foreach ($this->registry->hooks('run.complete') as $hook) {
            $hook($run);
        }
    }

    /** 节点执行前触发。 */
    public function fireNodeBefore(RunContext $ctx, NodeInterface $node, WorkflowState $state): void
    {
        foreach ($this->registry->hooks('node.before') as $hook) {
            $hook($ctx, $node, $state);
        }
    }

    /** 节点执行后触发（非 FAILED）。 */
    public function fireNodeAfter(
        RunContext $ctx,
        NodeInterface $node,
        WorkflowState $state,
        NodeExecutionResult $result,
    ): void {
        foreach ($this->registry->hooks('node.after') as $hook) {
            $hook($ctx, $node, $state, $result);
        }
    }

    /** 节点 FAILED 时触发。 */
    public function fireNodeFail(
        RunContext $ctx,
        NodeInterface $node,
        WorkflowState $state,
        NodeExecutionResult $result,
    ): void {
        foreach ($this->registry->hooks('node.fail') as $hook) {
            $hook($ctx, $node, $state, $result);
        }
    }

    /** 进入 WAITING（Pause）时触发。 */
    public function firePause(WorkflowRun $run, NodeInterface $node): void
    {
        foreach ($this->registry->hooks('pause') as $hook) {
            $hook($run, $node);
        }
    }

    /** resume 时触发。 */
    public function fireResume(WorkflowRun $run, array $feedback): void
    {
        foreach ($this->registry->hooks('resume') as $hook) {
            $hook($run, $feedback);
        }
    }
}
