<?php

declare(strict_types=1);

namespace Swoolefy\Support\Agent;

use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Neuron\NeuronFactory;

/**
 * 多 Agent 协程并发调度器 —— 路由与执行分离。
 *
 * {@see AgentRouterInterface} 决定调用哪些 Agent；本类只负责 GoWaitGroup 并发与结果汇聚。
 */
final class AgentScheduler
{
    public function __construct(
        private readonly NeuronFactory $neuronFactory,
        private readonly ?AgentRouterInterface $defaultRouter = null,
    ) {
    }

    public function neuronFactory(): NeuronFactory
    {
        return $this->neuronFactory;
    }

    /**
     * 并行执行选中的 Agent 任务。
     *
     * @param array<string, callable(RouterContext, NeuronFactory): mixed> $tasks
     *
     * @return array<string, mixed> agentId => output
     */
    public function runParallel(
        RouterContext $ctx,
        array $tasks,
        ?AgentRouterInterface $router = null,
    ): array {
        $router ??= $this->defaultRouter ?? new StaticRouter(array_keys($tasks));
        $selectedIds = $router->route($ctx);

        $callbacks = [];
        foreach ($selectedIds as $agentId) {
            if (!isset($tasks[$agentId])) {
                continue;
            }

            $task = $tasks[$agentId];
            $factory = $this->neuronFactory;
            $callbacks[$agentId] = static function () use ($task, $ctx, $factory, $agentId) {
                try {
                    return $task($ctx, $factory);
                } catch (\Throwable $e) {
                    return [
                        'error' => $e->getMessage(),
                        'agentId' => $agentId,
                    ];
                }
            };
        }

        if ($callbacks === []) {
            return [];
        }

        /** @var array<string, mixed> $results */
        if (\Swoole\Coroutine::getCid() < 0) {
            $results = [];
            foreach ($callbacks as $agentId => $callback) {
                $results[$agentId] = $callback();
            }
        } else {
            $results = GoWaitGroup::batchParallelRunWait($callbacks, $ctx->timeoutSeconds);
        }

        foreach ($results as $agentId => $output) {
            $ctx->state->setAgentOutput((string) $agentId, $output);
        }

        return $results;
    }
}
