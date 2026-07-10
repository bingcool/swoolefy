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

namespace Swoolefy\Support;

use PDO;
use RuntimeException;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Support\Mcp\McpStdioGuard;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Security\OutboundUrlGuard;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowPdoResolver;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use Throwable;

/**
 * 生产部署前配置 / Schema / 凭证健康检查。
 *
 * 建议在应用 bootstrap 或 CI deploy 阶段调用 {@see run()}，
 * 失败时抛出 RuntimeException 并列出全部错误，避免带着错误配置上线。
 *
 * 检查项：
 *   Neuron — default_vector_store 别名、Embedding 凭证、embedding_dimension、出站 URL
 *   Workflow — default_node_timeout_seconds、RunStore（生产禁止 memory）、DB 表可达性
 *
 * CLI 示例：
 *   php -r "require 'vendor/autoload.php'; Swoolefy\Support\ProductionHealthCheck::run();"
 */
final class ProductionHealthCheck
{
    /** Redis RunStore 配置 TTL 时的生产最低值，避免长 HITL 审批丢失 Run。 */
    private const MIN_REDIS_RUN_TTL_SECONDS = 604800;

    /**
     * 收集全部错误，不抛异常（适合 CI 汇总报告）。
     *
     * @return list<string> 错误描述；空数组表示通过
     */
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

    /**
     * 执行检查，任一失败则抛 RuntimeException。
     *
     * @throws RuntimeException 含全部错误信息的换行列表
     */
    public static function run(
        ?NeuronAiConfig $neuronConfig = null,
        ?WorkflowConfig $workflowConfig = null,
    ): void {
        $errors = self::check($neuronConfig, $workflowConfig);
        if ($errors !== []) {
            throw new \RuntimeException("Production health check failed:\n- " . implode("\n- ", $errors));
        }
    }

    /**
     * Neuron / RAG / MCP / 出站 URL 相关检查。
     *
     * @param list<string> $errors 追加式错误收集
     */
    private static function checkNeuron(NeuronAiConfig $config, array &$errors): void
    {
        // 默认向量库别名必须在 rag.vector_stores 中声明，防止 typo 导致运行时 fail-fast
        $alias = $config->defaultVectorStoreAlias();
        if (!$config->hasVectorStoreAlias($alias)) {
            $errors[] = "neuron: default_vector_store alias [{$alias}] is not declared in rag.vector_stores";
        }

        // 生产环境须能实例化真实 Embedding；allow_fake_embeddings 仅用于本地/单测
        if (!$config->allowFakeEmbeddings()) {
            try {
                (new EmbeddingFactory($config))->make();
            } catch (Throwable $e) {
                $errors[] = 'neuron: embedding not configured — ' . $e->getMessage();
            }
        }

        if ($config->embeddingDimension() < 1) {
            $errors[] = 'neuron: rag.embedding_dimension must be positive';
        }

        // 生产环境须开启多租户隔离，避免 RAG / Redis ChatHistory 跨租户串数据
        if (!$config->allowFakeEmbeddings() && !$config->requireTenantIsolation()) {
            $errors[] = 'neuron: rag.require_tenant_isolation must be true in production (RAG / ChatHistory tenant isolation)';
        }

        // 预校验所有已配置 Provider baseUri 与 MCP url 是否通过 OutboundUrlGuard
        $guard = $config->outboundUrlGuard();
        foreach ($config->outboundUrlsToValidate() as $label => $url) {
            try {
                $guard->assertAllowed($url, $label);
            } catch (\Throwable $e) {
                $errors[] = 'neuron: ' . $e->getMessage();
            }
        }
    }

    /**
     * Workflow 引擎相关检查。
     *
     * @param list<string> $errors 追加式错误收集
     */
    private static function checkWorkflow(WorkflowConfig $config, array &$errors): void
    {
        // 0 表示不限制超时，生产环境易导致节点永久挂起
        if ($config->defaultNodeTimeoutSeconds() <= 0) {
            $errors[] = 'workflow: default_node_timeout_seconds should be > 0 in production';
        }

        $driver = $config->runStoreDriver();

        if (self::isProductionEnvironment()) {
            // HITL resume / cancel / pause tasks / status 都暴露业务状态，生产必须显式启用鉴权。
            if (!$config->hitlAuthEnabled()) {
                $errors[] = 'workflow: hitl.auth_enabled must be true in production '
                    . '(set WORKFLOW_HITL_AUTH_ENABLED=1)';
            }

            // 当前 role header 依赖上游可信网关；没有共享 API Key 时容易被直接伪造。
            if ($config->hitlApiKey() === '') {
                $errors[] = 'workflow: hitl.api_key must be configured in production '
                    . '(set WORKFLOW_HITL_API_KEY)';
            }

            if ($driver === WorkflowRunStoreName::MEMORY) {
                $errors[] = 'workflow: default_run_store must not be memory in production '
                    . '(use db or redis for cross-worker HITL/resume; set WORKFLOW_RUN_STORE=db)';

                return;
            }

            if ($driver === WorkflowRunStoreName::REDIS) {
                $ttl = $config->redisTtl();
                if ($ttl > 0 && $ttl < self::MIN_REDIS_RUN_TTL_SECONDS) {
                    $errors[] = 'workflow: redis run store ttl is too short for production HITL '
                        . "(ttl={$ttl}; use 0 for no expiry or at least " . self::MIN_REDIS_RUN_TTL_SECONDS . ')';
                }
            }
        }

        // 非 DB RunStore 无需检查表结构
        if ($driver !== WorkflowRunStoreName::DB) {
            return;
        }

        // CLI 独立运行时可能未定义 APP_PATH，跳过 DB 探测
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

    private static function isProductionEnvironment(): bool
    {
        if (\defined('SWOOLEFY_ENV') && \defined('SWOOLEFY_PRD')) {
            return SystemEnv::isPrdEnv();
        }

        return strtolower((string) env('SWOOLEFY_ENV', '')) === 'prd';
    }
}
