<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
/**
 * 工作流薄门面（Facade）：封装 Definition → Compiler → Runtime 三层。
 *
 * 推荐用法：
 *   1. 构建 {@see WorkflowDefinition}（纯声明，无 I/O）
 *   2. {@see compile()} 得到只读 {@see CompiledWorkflow}
 *   3. {@see start()} 通过 {@see WorkflowEngine} 启动运行
 *
 * 快捷写法（脚本/测试）：
 *   Workflow::define('order_processing')->addNode(...)->compile()->start($input);
 *
 * @see docs/SwoolefyAI.md §3.3
 */
final class Workflow
{
    private WorkflowDefinition $definition;

    private ?CompiledWorkflow $compiled = null;

    private function __construct(WorkflowDefinition $definition)
    {
        $this->definition = $definition;
    }

    /**
     * 创建新的工作流定义构建器。
     *
     * @param string $id      工作流唯一标识
     * @param string $version 语义化版本，便于 Definition 版本化
     */
    public static function define(string $id, string $version = '1.0.0'): self
    {
        return new self(WorkflowDefinition::create($id, $version));
    }

    /**
     * 从已有 Definition 实例包装为 Facade。
     */
    public static function fromDefinition(WorkflowDefinition $definition): self
    {
        return new self($definition);
    }

    /**
     * 获取底层声明对象（可继续 addNode / addEdge）。
     */
    public function definition(): WorkflowDefinition
    {
        return $this->definition;
    }

    /**
     * 编译 Definition 为只读拓扑索引。
     * 生产环境可按 workflowId + version 缓存 CompiledWorkflow。
     */
    public function compile(?WorkflowCompiler $compiler = null): self
    {
        $compiler ??= WorkflowBootstrap::compiler();
        $this->compiled = $compiler->compile($this->definition);

        return $this;
    }

    /**
     * 获取已编译工作流；若未编译则自动 compile。
     */
    public function compiled(): CompiledWorkflow
    {
        if ($this->compiled === null) {
            $this->compile();
        }

        return $this->compiled;
    }

    /**
     * 启动一次新的工作流运行。
     *
     * @param array<string, mixed> $input  初始业务数据，写入 WorkflowState.data
     * @param WorkflowEngine|null  $engine 可选自定义引擎，默认 WorkflowBootstrap::engine()
     *
     * @return string runId，用于 resume / cancel / getRun 查询
     */
    public function start(array $input, ?WorkflowEngine $engine = null): string
    {
        $engine ??= WorkflowBootstrap::engine();

        return $engine->start($this->compiled(), $input);
    }

    /**
     * 恢复 WAITING 状态的 Run。
     *
     * @param array<string, mixed> $feedback HITL 反馈
     */
    public function resume(string $runId, array $feedback, ?WorkflowEngine $engine = null): void
    {
        ($engine ?? WorkflowBootstrap::engine())->resume($runId, $feedback);
    }

    /** 取消 Run。 */
    public function cancel(string $runId, ?WorkflowEngine $engine = null): void
    {
        ($engine ?? WorkflowBootstrap::engine())->cancel($runId);
    }

    /** 查询 Run 快照。 */
    public function getRun(string $runId, ?WorkflowEngine $engine = null): WorkflowRun
    {
        return ($engine ?? WorkflowBootstrap::engine())->getRun($runId);
    }
}
