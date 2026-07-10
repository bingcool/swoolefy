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

namespace Swoolefy\Support\Rag\Node;

use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * RAG 检索节点 —— 仅检索，将结果写入 state.data[outputKey]。
 *
 * 配置项：
 *   knowledgeBase — 知识库名
 *   queryKey      — state 中查询文本键，默认 question
 *   outputKey     — 写入键，默认 retrievedDocs
 *   topK          — 检索条数
 *   vectorStore   — 向量库别名（rag.vector_stores 的 key）；缺省用 default_vector_store
 *   tenantId      — 显式租户；缺省时从 state[tenantIdKey] 或 FrameworkContext 读取
 *   tenantIdKey   — state 中租户字段名，默认 tenantId
 *
 * 典型后续：条件边判断 retrievedDocs 是否为空 → AINode / RAGNode。
 */
final class RagRetrieveNode extends AbstractNode
{
    /** @param array<string, mixed> $config */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly RetrievalService $retrievalService,
    ) {
        parent::__construct($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $knowledgeBase = (string) ($this->config['knowledgeBase'] ?? 'default');
        $queryKey = (string) ($this->config['queryKey'] ?? 'question');
        $outputKey = (string) ($this->config['outputKey'] ?? 'retrievedDocs');
        $topK = (int) ($this->config['topK'] ?? 5);
        $storeAlias = $this->resolveStoreAlias();
        $tenantId = $this->resolveTenantId($state);

        $query = (string) $state->get($queryKey, '');
        $docs = $query === ''
            ? []
            : $this->retrievalService->retrieve($knowledgeBase, $query, $topK, $storeAlias, $tenantId);

        $state->set($outputKey, $docs);

        return NodeExecutionResult::success(
            [$outputKey => $docs],
            events: ['rag.retrieved' => [
                'runId' => $ctx->runId,
                'nodeId' => $this->nodeId,
                'knowledgeBase' => $knowledgeBase,
                'tenantId' => $tenantId,
                'docCount' => count($docs),
            ]],
            metrics: ['nodeType' => 'rag_retrieve', 'docCount' => count($docs)],
        );
    }

    /** 业务指定的向量库别名；未配置时返回 null（走 default_vector_store）。 */
    private function resolveStoreAlias(): ?string
    {
        $alias = $this->config['vectorStore'] ?? $this->config['vector_store'] ?? null;
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        return $alias;
    }

    /** 优先使用节点显式 tenantId，其次从 WorkflowState 读取 tenantIdKey。 */
    private function resolveTenantId(WorkflowState $state): ?string
    {
        $tenantId = $this->config['tenantId'] ?? $this->config['tenant_id'] ?? null;
        if (is_string($tenantId) && $tenantId !== '') {
            return $tenantId;
        }

        $tenantIdKey = $this->config['tenantIdKey'] ?? $this->config['tenant_id_key'] ?? 'tenantId';
        if (!is_string($tenantIdKey) || $tenantIdKey === '') {
            return null;
        }

        $fromState = $state->get($tenantIdKey);

        return is_string($fromState) && $fromState !== '' ? $fromState : null;
    }
}
