<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Redis;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\RedisRunStore;
use Swoolefy\Support\Workflow\Engine\RunStoreInterface;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\ApplicationConfig;

/**
 * 生产级 Workflow 组件工厂 —— 从 workflow.yaml + env 装配 Engine / RunStore。
 */
final class WorkflowComponentFactory
{
    public static function conditionEvaluator(?WorkflowConfig $config = null): ConditionEvaluatorInterface
    {
        $config ??= WorkflowConfig::load();

        return ConditionEvaluatorFactory::create($config->conditionEvaluator());
    }

    public static function runStore(WorkflowRegistry $registry, ?WorkflowConfig $config = null): RunStoreInterface
    {
        $config ??= WorkflowConfig::load();
        if ($config->runStoreDriver() !== 'redis') {
            return new InMemoryRunStore();
        }

        $redisCfg = $config->redisSection();
        $host = ApplicationConfig::pickStringEnvFirst($redisCfg, 'host', 'REDIS_HOST', '127.0.0.1');
        $port = ApplicationConfig::pickIntEnvFirst($redisCfg, 'port', 'REDIS_PORT', 6379);
        $prefix = ApplicationConfig::pickStringEnvFirst($redisCfg, 'prefix', 'WORKFLOW_REDIS_PREFIX', 'workflow:run:');
        $ttl = ApplicationConfig::pickIntEnvFirst($redisCfg, 'ttl', 'WORKFLOW_REDIS_TTL', 86400);

        $redis = new Redis();
        $redis->connect($host, $port);

        return new RedisRunStore($redis, $registry, $prefix, $ttl);
    }

    public static function engine(
        WorkflowRegistry $registry,
        ?PluginManager $plugins = null,
        ?WorkflowEventDispatcherInterface $events = null,
        ?WorkflowConfig $config = null,
    ): WorkflowEngine {
        $config ??= WorkflowConfig::load();
        $evaluator = self::conditionEvaluator($config);

        return new WorkflowEngine(
            plugins: $plugins ?? new PluginManager(WorkflowBootstrap::defaultPluginsPublic()),
            scheduler: new DagScheduler($evaluator),
            runStore: self::runStore($registry, $config),
            events: $events ?? new StreamWorkflowEventDispatcher(),
        );
    }

    public static function subWorkflowRunner(WorkflowRegistry $registry, ?WorkflowConfig $config = null): SubWorkflowRunner
    {
        return new SubWorkflowRunner(self::engine($registry, config: $config));
    }

    public static function compiler(?WorkflowConfig $config = null): WorkflowCompiler
    {
        return new WorkflowCompiler(self::conditionEvaluator($config));
    }
}
