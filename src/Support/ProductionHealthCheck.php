<?php

declare(strict_types=1);

namespace Swoolefy\Support;

use PDO;
use RuntimeException;
use Swoolefy\Support\Mcp\McpStdioGuard;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Security\OutboundUrlGuard;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowPdoResolver;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;

/**
 * 生产启动期健康检查 —— 配置 / Schema / 凭证。
 *
 * CLI：php -r "require 'vendor/autoload.php'; Swoolefy\Support\ProductionHealthCheck::run();"
 */
final class ProductionHealthCheck
{
    /** @return list<string> 错误信息；空数组表示通过 */
    public static function check(
        ?NeuronAiConfig $neuronConfig = null,
        ?WorkflowConfig $workflowConfig = null,
    ): array {
        $errors = [];
        $neuron = $neuronConfig ?? NeuronAiConfig::load();
        $workflow = $workflowConfig ?? WorkflowConfig::load();

        self::checkNeuron($neuron, $errors);
        self::checkWorkflow($workflow, $errors);

        return $errors;
    }

    /** @throws \RuntimeException */
    public static function run(
        ?NeuronAiConfig $neuronConfig = null,
        ?WorkflowConfig $workflowConfig = null,
    ): void {
        $errors = self::check($neuronConfig, $workflowConfig);
        if ($errors !== []) {
            throw new \RuntimeException("Production health check failed:\n- " . implode("\n- ", $errors));
        }
    }

    /** @param list<string> $errors */
    private static function checkNeuron(NeuronAiConfig $config, array &$errors): void
    {
        $alias = $config->defaultVectorStoreAlias();
        if (!$config->hasVectorStoreAlias($alias)) {
            $errors[] = "neuron: default_vector_store alias [{$alias}] is not declared in rag.vector_stores";
        }

        if (!$config->allowFakeEmbeddings()) {
            try {
                (new \Swoolefy\Support\Neuron\Embedding\EmbeddingFactory($config))->make();
            } catch (\Throwable $e) {
                $errors[] = 'neuron: embedding not configured — ' . $e->getMessage();
            }
        }

        if ($config->embeddingDimension() < 1) {
            $errors[] = 'neuron: rag.embedding_dimension must be positive';
        }

        $guard = $config->outboundUrlGuard();
        foreach ($config->outboundUrlsToValidate() as $label => $url) {
            try {
                $guard->assertAllowed($url, $label);
            } catch (\Throwable $e) {
                $errors[] = 'neuron: ' . $e->getMessage();
            }
        }
    }

    /** @param list<string> $errors */
    private static function checkWorkflow(WorkflowConfig $config, array &$errors): void
    {
        if ($config->defaultNodeTimeoutSeconds() <= 0) {
            $errors[] = 'workflow: default_node_timeout_seconds should be > 0 in production';
        }

        if ($config->runStoreDriver() !== WorkflowRunStoreName::DB) {
            return;
        }

        if (!defined('APP_PATH') || (string) APP_PATH === '') {
            return;
        }

        try {
            $pdo = WorkflowPdoResolver::resolve($config->dbComponent());
            $table = $config->dbTable();
            $stmt = $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            if ($stmt === false) {
                $errors[] = "workflow: table [{$table}] is not accessible; run Schema/workflow_runs.sql";
            }
        } catch (\Throwable $e) {
            $errors[] = 'workflow: db run store check failed — ' . $e->getMessage()
                . ' (execute Schema/workflow_runs.sql)';
        }
    }
}
