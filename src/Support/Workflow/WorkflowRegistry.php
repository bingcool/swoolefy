<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 工作流定义注册表 —— HTTP API 按 workflowId 解析 Definition / CompiledWorkflow。
 *
 * 典型用法：
 *   $registry->register('order_processing', fn () => OrderProcessingWorkflow::definition());
 *   $compiled = $registry->compiled('order_processing');
 *   $engine->start($compiled, $input);
 */
final class WorkflowRegistry
{
    /** @var array<string, callable(): WorkflowDefinition> */
    private array $factories = [];

    /** @var array<string, CompiledWorkflow> */
    private array $compiledCache = [];

    /**
     * 注册工作流工厂（惰性：首次 compiled/definition 时才构建 Definition）。
     *
     * @param callable(): WorkflowDefinition $factory
     */
    public function register(string $workflowId, callable $factory): void
    {
        $this->factories[$workflowId] = $factory;
        // 定义变更后清除旧编译缓存，避免返回过期 DAG。
        unset($this->compiledCache[$workflowId]);
    }

    /** 是否已注册指定 workflowId。 */
    public function has(string $workflowId): bool
    {
        return isset($this->factories[$workflowId]);
    }

    /**
     * 已注册的 workflowId 列表（按注册顺序）。
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->factories);
    }

    /**
     * 获取原始 Definition（每次调用工厂，不走编译缓存）。
     *
     * @throws WorkflowException 未注册或工厂返回的 id 与注册键不一致
     */
    public function definition(string $workflowId): WorkflowDefinition
    {
        $factory = $this->factories[$workflowId] ?? null;
        if ($factory === null) {
            throw new WorkflowException("Workflow {$workflowId} is not registered");
        }

        $definition = $factory();
        if ($definition->id() !== $workflowId) {
            throw new WorkflowException("Workflow factory returned mismatched id {$definition->id()}");
        }

        return $definition;
    }

    /**
     * 获取编译后的只读 DAG（带缓存）。
     *
     * @throws WorkflowException 未注册或编译失败
     */
    public function compiled(string $workflowId, ?WorkflowCompiler $compiler = null): CompiledWorkflow
    {
        $cacheKey = $workflowId;
        if (isset($this->compiledCache[$cacheKey])) {
            return $this->compiledCache[$cacheKey];
        }

        $compiler ??= WorkflowBootstrap::compiler();
        $compiled = $compiler->compile($this->definition($workflowId));
        $this->compiledCache[$cacheKey] = $compiled;

        return $compiled;
    }

    /** 清空编译缓存（测试 reset 或热更新定义时调用）。 */
    public function clearCompiledCache(): void
    {
        $this->compiledCache = [];
    }
}
