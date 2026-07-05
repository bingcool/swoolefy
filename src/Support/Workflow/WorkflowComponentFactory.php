<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

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
 * 生产推荐：
 *   - 默认 default_run_store=db（可查询、可审计、易备份）
 *   - 或 redis（低延迟 HITL）；db 须预执行 Schema/workflow_runs.sql
 */
final class WorkflowComponentFactory
{
    public static function conditionEvaluator(?WorkflowConfig $config = null): ConditionEvaluatorInterface
    {
        $config ??= WorkflowConfig::load();

        return ConditionEvaluatorFactory::create($config->conditionEvaluator());
    }

    /**
     * 按配置构建 RunStore。
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
        $driver = $config->runStoreDriver($alias);

        if (!WorkflowRunStoreName::isSupported($driver)) {
            throw new WorkflowException(
                "Unsupported workflow run_store driver [{$driver}] for alias [{$alias}]",
            );
        }

        return match ($driver) {
            WorkflowRunStoreName::REDIS => self::makeRedisRunStore($registry, $config, $alias),
            WorkflowRunStoreName::DB => self::makeDbRunStore($registry, $config, $alias),
            default => new InMemoryRunStore(),
        };
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
        return new RedisRunStore(
            WorkflowRedisResolver::resolve($config->redisComponent($alias)),
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
        return new DbRunStore(
            WorkflowPdoResolver::resolve($config->dbComponent($alias)),
            $registry,
            $config->dbTable($alias),
        );
    }
}
