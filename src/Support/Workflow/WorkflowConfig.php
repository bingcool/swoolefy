<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\ApplicationConfig;

/**
 * Workflow 引擎配置加载器。
 *
 * 读取 APP_PATH/config/workflow.php（可选），环境变量优先。
 * RAG / MCP / Neuron 见 {@see \Swoolefy\Support\Neuron\NeuronAiConfig}。
 */
final class WorkflowConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('workflow.php'));
    }

    public function runStoreDriver(): string
    {
        $section = (array) ($this->config['workflow'] ?? []);

        return ApplicationConfig::pickStringEnvFirst($section, 'run_store', 'WORKFLOW_RUN_STORE', 'memory');
    }

    /** @return array<string, mixed> */
    public function redisSection(): array
    {
        return (array) (($this->config['workflow']['redis'] ?? []) ?: []);
    }

    /** Redis 组件别名，对应 Config/component/cache.php 中的 key（如 redis / predis）。 */
    public function redisComponent(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->redisSection(),
            'component',
            'WORKFLOW_REDIS_COMPONENT',
            'redis',
        );
    }

    public function redisPrefix(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->redisSection(),
            'prefix',
            'WORKFLOW_REDIS_PREFIX',
            'workflow:run:',
        );
    }

    public function redisTtl(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->redisSection(),
            'ttl',
            'WORKFLOW_REDIS_TTL',
            86400,
        );
    }

    public function conditionEvaluator(): string
    {
        $section = (array) ($this->config['workflow'] ?? []);

        return ApplicationConfig::pickStringEnvFirst($section, 'condition_evaluator', 'WORKFLOW_CONDITION_EVALUATOR', 'symfony');
    }
}
