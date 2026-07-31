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

use Swoolefy\Core\SystemEnv;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\RedisRunStore;
use Swoolefy\Support\Workflow\Engine\RunStoreInterface;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Plugin\PluginManager;

/**
 * 生产级 Workflow 组件工厂 —— 从 workflow.php 装配 Engine / RunStore。
 *
 * RunStore 选择：
 *   workflow.default_run_store → workflow.run_stores[alias]
 *   驱动：memory | redis | db（section.driver 可覆盖别名）
 *
 * RunStore ↔ Registry 绑定：
 *   缓存键 = {@see WorkflowRegistry::id()} + ':' + storeAlias（稳定标识，不用 spl_object_id）。
 *   Redis/DB 水合依赖构造时注入的 Registry；换 Registry 须先 {@see releaseRegistry()}。
 *   约定：Registry **进程级复用**，谁注册谁启动谁查询；禁止按请求 new Registry。
 *
 * 协程安全：
 *   共享 RunStore 适配器本身 OK，隔离单元是 runId。
 *   Engine / Tracing / Plugins 仍由调用方（Bootstrap Context 或每次 new）隔离，本工厂不缓存 Engine。
 *   Redis/DB 连接按每次 IO resolve，避免把首次 cid 的 PDO/Redis 冻在进程级 Store 上。
 *
 * 生产推荐：
 *   - 默认 default_run_store=db（可查询、可审计、易备份）
 *   - 或 redis（低延迟 HITL）；db 须预执行 Schema/workflow_runs.sql
 */
final class WorkflowComponentFactory
{
    /** @var array<string, RunStoreInterface> key = registryId + ':' + alias */
    private static array $runStoreCache = [];

    /** 生产环境 memory 警告只打一次，避免刷屏。 */
    private static bool $productionMemoryWarned = false;

    public static function conditionEvaluator(?WorkflowConfig $config = null): ConditionEvaluatorInterface
    {
        $config ??= WorkflowConfig::load();

        return ConditionEvaluatorFactory::create($config->conditionEvaluator());
    }

    /**
     * 按配置构建（或复用）与 Registry 绑定的 RunStore。
     *
     * 进程级缓存：同一 registryId + alias 复用适配器；连接不在此冻结。
     *
     * @param string|null $storeAlias 覆盖 default_run_store
     */
    public static function runStore(
        WorkflowRegistry $registry,
        ?WorkflowConfig $config = null,
        ?string $storeAlias = null,
    ): RunStoreInterface {
        $config ??= WorkflowConfig::load();
        $alias = $storeAlias ?? $config->defaultRunStoreAlias();
        // 稳定 Registry 标识作缓存键，避免仅用 spl_object_id 导致临时 Registry 碎片堆积
        $cacheKey = $registry->id() . ':' . $alias;

        if (isset(self::$runStoreCache[$cacheKey])) {
            return self::$runStoreCache[$cacheKey];
        }

        $driver = $config->runStoreDriver($alias);

        if (!WorkflowRunStoreName::isSupported($driver)) {
            throw new WorkflowException(
                "Unsupported workflow run_store driver [{$driver}] for alias [{$alias}]",
            );
        }

        if ($driver === WorkflowRunStoreName::MEMORY) {
            self::warnProductionMemoryStore($alias);
        }

        $store = match ($driver) {
            WorkflowRunStoreName::REDIS => self::makeRedisRunStore($registry, $config, $alias),
            WorkflowRunStoreName::DB => self::makeDbRunStore($registry, $config, $alias),
            default => new InMemoryRunStore(),
        };

        self::$runStoreCache[$cacheKey] = $store;

        return $store;
    }

    /**
     * 释放某 Registry 绑定的全部 RunStore 缓存（应用 reload / 替换 Registry 时调用）。
     *
     * 释放后旧 Registry 与 RunStore 可被 GC 回收。
     */
    public static function releaseRegistry(string $registryId): void
    {
        $prefix = $registryId . ':';
        foreach (array_keys(self::$runStoreCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$runStoreCache[$key]);
            }
        }
    }

    /** 清空全部 RunStore 缓存（单测隔离）。 */
    public static function resetRunStores(): void
    {
        self::$runStoreCache = [];
        self::$productionMemoryWarned = false;
    }

    /** @internal 单测断言缓存项数量 */
    public static function cachedRunStoreCount(): int
    {
        return count(self::$runStoreCache);
    }

    public static function engine(
        WorkflowRegistry $registry,
        ?PluginManager $plugins = null,
        ?WorkflowEventDispatcherInterface $events = null,
        ?WorkflowConfig $config = null,
        ?string $storeAlias = null,
    ): WorkflowEngine {
        $config ??= WorkflowConfig::load();
        $evaluator = self::conditionEvaluator($config);

        return new WorkflowEngine(
            plugins: $plugins ?? new PluginManager(WorkflowBootstrap::defaultPluginsPublic()),
            scheduler: new DagScheduler($evaluator),
            runStore: self::runStore($registry, $config, $storeAlias),
            events: $events ?? new StreamWorkflowEventDispatcher(),
            defaultNodeTimeoutSeconds: $config->defaultNodeTimeoutSeconds(),
        );
    }

    public static function subWorkflowRunner(
        WorkflowRegistry $registry,
        ?WorkflowConfig $config = null,
        ?string $storeAlias = null,
    ): SubWorkflowRunner {
        return new SubWorkflowRunner(self::engine($registry, config: $config, storeAlias: $storeAlias));
    }

    public static function compiler(?WorkflowConfig $config = null): WorkflowCompiler
    {
        return new WorkflowCompiler(self::conditionEvaluator($config));
    }

    private static function makeRedisRunStore(
        WorkflowRegistry $registry,
        WorkflowConfig $config,
        string $alias,
    ): RedisRunStore {
        $component = $config->redisComponent($alias);

        // 进程级缓存 Store，但每次 IO 从当前 Application 取连接，避免冻死首次 cid 的 Redis
        return new RedisRunStore(
            static fn () => WorkflowRedisResolver::resolve($component),
            $registry,
            $config->redisPrefix($alias),
            $config->redisTtl($alias),
        );
    }

    private static function makeDbRunStore(
        WorkflowRegistry $registry,
        WorkflowConfig $config,
        string $alias,
    ): DbRunStore {
        $component = $config->dbComponent($alias);

        // 进程级缓存 Store，但每次 IO 从当前 Application 取 PDO，避免冻死首次 cid 的连接
        return new DbRunStore(
            static fn () => WorkflowPdoResolver::resolve($component),
            $registry,
            $config->dbTable($alias),
        );
    }

    /**
     * 生产环境配置 memory 时高可见警告；不改变开发默认值，也不强制改驱动。
     */
    private static function warnProductionMemoryStore(string $alias): void
    {
        if (self::$productionMemoryWarned || !self::isProductionEnvironment()) {
            return;
        }

        self::$productionMemoryWarned = true;
        $message = '[swoolefy][workflow] CRITICAL: run_store driver=memory in production '
            . "(alias={$alias}). Cross-worker HITL/resume will lose state; use db or redis "
            . '(WORKFLOW_RUN_STORE=db).';
        trigger_error($message, E_USER_WARNING);
        error_log($message);
    }

    private static function isProductionEnvironment(): bool
    {
        if (\defined('SWOOLEFY_ENV') && \defined('SWOOLEFY_PRD')) {
            return SystemEnv::isPrdEnv();
        }

        return strtolower((string) env('SWOOLEFY_ENV', '')) === 'prd';
    }
}
