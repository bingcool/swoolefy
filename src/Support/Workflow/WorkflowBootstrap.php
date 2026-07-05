<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowEventDispatcherInterface;
use Swoolefy\Support\Workflow\Plugin\Builtin\AuditPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\MetricsPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\OpenTelemetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\PermissionPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

/**
 * Phase 1 默认组件装配入口（演示与单测用）。
 *
 * 单例策略：**协程上下文隔离**（{@see SwooleContext}），禁止进程级 static 缓存。
 * 同一 Worker 内不同协程各自持有独立的 Engine / Compiler / Evaluator，
 * 避免 TracingPlugin span、InMemoryRunStore 等状态串协程。
 *
 * 非协程 CLI（无 Context）时：每次调用新建实例，不缓存；
 * 若需在同一脚本内复用 Engine，请显式传递 {@see WorkflowEngine} 实例。
 *
 * 生产应用应在 services.php 中注册组件，而非依赖本类。
 *
 * @see docs/swoolefyAI.md §8.2
 */
final class WorkflowBootstrap
{
    private const KEY_ENGINE = 'swoolefy.support.workflow.engine';

    private const KEY_COMPILER = 'swoolefy.support.workflow.compiler';

    private const KEY_EVALUATOR = 'swoolefy.support.workflow.condition_evaluator';

    /**
     * 获取当前协程内的 WorkflowEngine（内置 Retry + Tracing + Metrics）。
     *
     * @param PluginManager|null                      $plugins 传入则始终新建独立 Engine，不写入协程缓存
     * @param WorkflowEventDispatcherInterface|null   $events  对外事件分发（SSE 等），默认 Null + StreamBridge
     */
    public static function engine(
        ?PluginManager $plugins = null,
        ?WorkflowEventDispatcherInterface $events = null,
    ): WorkflowEngine {
        if ($plugins !== null) {
            return self::buildEngine($plugins, $events);
        }

        $cached = self::getFromContext(self::KEY_ENGINE);
        if ($cached instanceof WorkflowEngine) {
            return $cached;
        }

        $engine = self::buildEngine(new PluginManager(self::defaultPlugins()), $events);
        self::setInContext(self::KEY_ENGINE, $engine);

        return $engine;
    }

    /**
     * 清除当前协程内缓存的 Workflow 组件（单测隔离用）。
     */
    public static function reset(): void
    {
        self::deleteFromContext(self::KEY_ENGINE);
        self::deleteFromContext(self::KEY_COMPILER);
        self::deleteFromContext(self::KEY_EVALUATOR);
    }

    /**
     * 获取当前协程内的 WorkflowCompiler（共享同一 Evaluator 实例）。
     */
    public static function compiler(): WorkflowCompiler
    {
        $cached = self::getFromContext(self::KEY_COMPILER);
        if ($cached instanceof WorkflowCompiler) {
            return $cached;
        }

        $compiler = new WorkflowCompiler(self::conditionEvaluator());
        self::setInContext(self::KEY_COMPILER, $compiler);

        return $compiler;
    }

    /**
     * 默认插件列表（供 WorkflowComponentFactory 复用）。
     *
     * @return list<WorkflowPluginInterface>
     */
    public static function defaultPluginsPublic(): array
    {
        return self::defaultPlugins();
    }

    /**
     * 当前协程内共享的条件求值器（Compiler 与 DagScheduler 复用）。
     */
    private static function conditionEvaluator(): ConditionEvaluatorInterface
    {
        $cached = self::getFromContext(self::KEY_EVALUATOR);
        if ($cached instanceof ConditionEvaluatorInterface) {
            return $cached;
        }

        $evaluator = ConditionEvaluatorFactory::create();
        self::setInContext(self::KEY_EVALUATOR, $evaluator);

        return $evaluator;
    }

    /** 组装 WorkflowEngine。 */
    private static function buildEngine(
        PluginManager $plugins,
        ?WorkflowEventDispatcherInterface $events = null,
    ): WorkflowEngine {
        $config = WorkflowConfig::load();

        return new WorkflowEngine(
            plugins: $plugins,
            scheduler: new DagScheduler(self::conditionEvaluator()),
            events: $events ?? new StreamWorkflowEventDispatcher(),
            defaultNodeTimeoutSeconds: $config->defaultNodeTimeoutSeconds(),
        );
    }

    /**
     * 默认插件链：Retry + Tracing + Metrics；Phase 3~4 可按 env 追加扩展插件。
     *
     * 环境开关：
     *   WORKFLOW_OTEL_ENABLED、WORKFLOW_AUDIT_ENABLED
     *   WORKFLOW_RATE_LIMIT_ENABLED + WORKFLOW_MAX_CONCURRENT_RUNS
     *   WORKFLOW_PERMISSION_ENABLED + WORKFLOW_ALLOWED_ROLES
     *
     * @return list<WorkflowPluginInterface>
     */
    private static function defaultPlugins(): array
    {
        $plugins = [
            new RetryPlugin(),
            new TracingPlugin(),
            new MetricsPlugin(),
        ];

        if (self::envFlag('WORKFLOW_OTEL_ENABLED')) {
            $plugins[] = new OpenTelemetryPlugin();
        }

        if (self::envFlag('WORKFLOW_AUDIT_ENABLED')) {
            $plugins[] = new AuditPlugin();
        }

        if (self::envFlag('WORKFLOW_RATE_LIMIT_ENABLED')) {
            $max = (int) (getenv('WORKFLOW_MAX_CONCURRENT_RUNS') ?: 50);
            $plugins[] = RateLimitPlugin::make(max(1, $max));
        }

        if (self::envFlag('WORKFLOW_PERMISSION_ENABLED')) {
            $roles = array_filter(array_map('trim', explode(',', (string) (getenv('WORKFLOW_ALLOWED_ROLES') ?: ''))));
            $plugins[] = new PermissionPlugin($roles !== [] ? $roles : ['admin', 'operator']);
        }

        return $plugins;
    }

    /** 读取 env 开关（1 / true / yes 视为启用）。 */
    private static function envFlag(string $name): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return mixed|null */
    private static function getFromContext(string $key): mixed
    {
        $context = self::coroutineContext();
        if ($context === null) {
            return null;
        }

        return $context[$key] ?? null;
    }

    private static function setInContext(string $key, mixed $value): void
    {
        $context = self::coroutineContext();
        if ($context === null) {
            return;
        }

        $context[$key] = $value;
    }

    private static function deleteFromContext(string $key): void
    {
        $context = self::coroutineContext();
        if ($context === null) {
            return;
        }

        unset($context[$key]);
    }

    /**
     * 获取 Swoole 协程上下文；非协程环境返回 null（不降级为进程 static）。
     */
    private static function coroutineContext(): ?\ArrayObject
    {
        try {
            $context = SwooleContext::getContext();
        } catch (\Throwable) {
            return null;
        }

        return $context instanceof \ArrayObject ? $context : null;
    }
}
