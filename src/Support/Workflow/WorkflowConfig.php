<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\ApplicationConfig;
use Symfony\Component\Yaml\Yaml;

/**
 * Workflow / RAG / MCP / Neuron 模块配置加载器。
 *
 * 读取 APP_PATH/config/workflow.yaml（可选），环境变量优先。
 */
final class WorkflowConfig
{
    /** @param array<string, mixed> $yaml */
    private function __construct(
        private readonly array $yaml,
    ) {
    }

    public static function load(): self
    {
        $yaml = [];
        if (ApplicationConfig::hasApplicationYaml()) {
            try {
                $appPath = ApplicationConfig::resolveAppPath();
                $configFile = $appPath . '/config/workflow.yaml';
                if (is_file($configFile)) {
                    $yaml = (array) Yaml::parseFile($configFile);
                }
            } catch (\Throwable) {
                $yaml = [];
            }
        }

        return new self($yaml);
    }

    public function runStoreDriver(): string
    {
        $section = (array) ($this->yaml['workflow'] ?? []);

        return ApplicationConfig::pickStringEnvFirst($section, 'run_store', 'WORKFLOW_RUN_STORE', 'memory');
    }

    /** @return array<string, mixed> */
    public function redisSection(): array
    {
        return (array) (($this->yaml['workflow']['redis'] ?? []) ?: []);
    }

    public function conditionEvaluator(): string
    {
        $section = (array) ($this->yaml['workflow'] ?? []);

        return ApplicationConfig::pickStringEnvFirst($section, 'condition_evaluator', 'WORKFLOW_CONDITION_EVALUATOR', 'symfony');
    }

    /** @return array<string, mixed> */
    public function ragSection(): array
    {
        return (array) ($this->yaml['rag'] ?? []);
    }

    /** @return array<string, mixed> */
    public function mcpSection(): array
    {
        return (array) ($this->yaml['mcp'] ?? []);
    }
}
