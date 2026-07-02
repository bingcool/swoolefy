<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 工作流定义注册表 —— HTTP API 按 workflowId 解析 Definition / CompiledWorkflow。
 */
final class WorkflowRegistry
{
    /** @var array<string, callable(): WorkflowDefinition> */
    private array $factories = [];

    /** @var array<string, CompiledWorkflow> */
    private array $compiledCache = [];

    /**
     * @param callable(): WorkflowDefinition $factory
     */
    public function register(string $workflowId, callable $factory): void
    {
        $this->factories[$workflowId] = $factory;
    }

    public function has(string $workflowId): bool
    {
        return isset($this->factories[$workflowId]);
    }

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

    public function clearCompiledCache(): void
    {
        $this->compiledCache = [];
    }
}
