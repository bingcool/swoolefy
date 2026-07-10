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

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * 工作流定义注册表 —— workflowId + version 多版本管理。
 *
 * 设计要点：
 *   - register() 注册 latest，并同步索引 Definition.version
 *   - registerVersion() 仅追加历史版本，不改变 latest
 *   - compiled() 按 workflowId@version 缓存 CompiledWorkflow
 *   - Run 快照持久化 version；hydrate 时校验 Registry 中仍存在该版本
 *
 * 典型用法：
 *   $registry->register('order', fn () => OrderWorkflow::definition()); // v2 latest
 *   $registry->registerVersion('order', '1.0.0', fn () => OrderWorkflow::v1());
 *   $registry->compiled('order');           // latest
 *   $registry->compiled('order', '1.0.0');  // 指定版本 resume
 */
final class WorkflowRegistry
{
    /** @var array<string, callable(): WorkflowDefinition> workflowId => latest factory */
    private array $factories = [];

    /** @var array<string, array<string, callable(): WorkflowDefinition>> workflowId => version => factory */
    private array $versionedFactories = [];

    /** @var array<string, CompiledWorkflow> cacheKey => compiled */
    private array $compiledCache = [];

    /**
     * 注册工作流工厂（作为 latest，并按 Definition.version 建立版本索引）。
     *
     * @param callable(): WorkflowDefinition $factory
     */
    public function register(string $workflowId, callable $factory): void
    {
        $definition = $factory();
        if ($definition->id() !== $workflowId) {
            throw new WorkflowException("Workflow factory returned mismatched id {$definition->id()}");
        }

        $version = $definition->version();
        $this->factories[$workflowId] = $factory;
        $this->versionedFactories[$workflowId][$version] = $factory;
        $this->clearCompiledCacheFor($workflowId);
    }

    /**
     * 注册指定历史版本（不改变 latest）。
     *
     * 用于灰度 / 回滚：旧 Run 仍可按快照 version resume，
     * 新 start() 默认走 register() 注册的 latest。
     *
     * @param callable(): WorkflowDefinition $factory 工厂返回的 Definition.version 须与 $version 一致
     */
    public function registerVersion(string $workflowId, string $version, callable $factory): void
    {
        $definition = $factory();
        if ($definition->id() !== $workflowId) {
            throw new WorkflowException("Workflow factory returned mismatched id {$definition->id()}");
        }
        if ($definition->version() !== $version) {
            throw new WorkflowException(
                "Workflow factory version {$definition->version()} does not match registered version {$version}",
            );
        }

        $this->versionedFactories[$workflowId][$version] = $factory;
        unset($this->compiledCache[$this->cacheKey($workflowId, $version)]);
    }

    /** 是否已注册指定 workflowId（任意版本 / latest）。 */
    public function has(string $workflowId): bool
    {
        return isset($this->factories[$workflowId]);
    }

    /** 是否已注册指定 workflowId + version。 */
    public function hasVersion(string $workflowId, string $version): bool
    {
        return isset($this->versionedFactories[$workflowId][$version]);
    }

    /**
     * @return list<string>
     */
    public function versions(string $workflowId): array
    {
        return array_keys($this->versionedFactories[$workflowId] ?? []);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @throws WorkflowException
     */
    public function definition(string $workflowId, ?string $version = null): WorkflowDefinition
    {
        $factory = $this->resolveFactory($workflowId, $version);
        $definition = $factory();
        if ($definition->id() !== $workflowId) {
            throw new WorkflowException("Workflow factory returned mismatched id {$definition->id()}");
        }
        if ($version !== null && $definition->version() !== $version) {
            throw new WorkflowException(
                "Workflow {$workflowId} factory version {$definition->version()} != requested {$version}",
            );
        }

        return $definition;
    }

    /**
     * 获取已编译工作流（带 workflowId@version 缓存）。
     *
     * @param string|null $version null 时使用 latestVersion()
     *
     * @throws WorkflowException 未注册
     */
    public function compiled(
        string $workflowId,
        ?string $version = null,
        ?WorkflowCompiler $compiler = null,
    ): CompiledWorkflow {
        $resolvedVersion = $version ?? $this->latestVersion($workflowId);
        $cacheKey = $this->cacheKey($workflowId, $resolvedVersion);
        if (isset($this->compiledCache[$cacheKey])) {
            return $this->compiledCache[$cacheKey];
        }

        $compiler ??= WorkflowBootstrap::compiler();
        $compiled = $compiler->compile($this->definition($workflowId, $resolvedVersion));
        $this->compiledCache[$cacheKey] = $compiled;

        return $compiled;
    }

    public function clearCompiledCache(): void
    {
        $this->compiledCache = [];
    }

    /** @throws WorkflowException */
    public function latestVersion(string $workflowId): string
    {
        if (!isset($this->factories[$workflowId])) {
            throw new WorkflowException("Workflow {$workflowId} is not registered");
        }

        return $this->factories[$workflowId]()->version();
    }

    /** @throws WorkflowException */
    private function resolveFactory(string $workflowId, ?string $version): callable
    {
        if ($version === null) {
            $factory = $this->factories[$workflowId] ?? null;
            if ($factory === null) {
                throw new WorkflowException("Workflow {$workflowId} is not registered");
            }

            return $factory;
        }

        $factory = $this->versionedFactories[$workflowId][$version] ?? null;
        if ($factory === null) {
            throw new WorkflowException("Workflow {$workflowId} version {$version} is not registered");
        }

        return $factory;
    }

    private function cacheKey(string $workflowId, string $version): string
    {
        return $workflowId . '@' . $version;
    }

    private function clearCompiledCacheFor(string $workflowId): void
    {
        foreach (array_keys($this->compiledCache) as $key) {
            if (str_starts_with($key, $workflowId . '@')) {
                unset($this->compiledCache[$key]);
            }
        }
    }
}
