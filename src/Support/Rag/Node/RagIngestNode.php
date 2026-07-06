<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Node;

use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\RagIngestDispatcher;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * RAG 文档入库工作流节点。
 *
 * 从 WorkflowState 读取文本列表，经入库 Dispatcher 处理。
 *
 * 默认 mode=sync 时保持旧行为：节点内同步 embed + 写 VectorStore。
 * mode=queue 时只提交 RagIngestJob 给配置化 producer，后台 consumer 再调用入库管线。
 *
 * 配置项：
 *   knowledgeBase — 目标知识库（VectorStore index / 目录 / collection 名）
 *   sourceKey     — state.data 键，值为 string 或 list<string>
 *   vectorStore   — rag.vector_stores 别名；缺省用 default_vector_store
 *   tenantId      — 显式租户；缺省时从 state[tenantIdKey] 或 FrameworkContext 读取
 *   tenantIdKey   — state 中租户字段名，默认 tenantId
 *
 * 对外事件：rag.ingest.completed
 *
 * @see IngestionPipeline
 * @see VectorStoreFactory
 */
final class RagIngestNode extends AbstractNode
{
    /**
     * @param array<string, mixed> $config   节点配置（knowledgeBase / sourceKey / vectorStore）
     * @param IngestionPipeline    $pipeline 入库管线（Embedding + VectorStore，DI 便于单测 mock）
     */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly IngestionPipeline $pipeline,
        private readonly ?RagIngestDispatcher $dispatcher = null,
    ) {
        parent::__construct($nodeId);
    }

    /**
     * 执行同步入库。
     *
     * 流程：读 sourceKey → normalizeTexts → IngestionPipeline::ingest → 写 ingestedCount
     */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $knowledgeBase = (string) ($this->config['knowledgeBase'] ?? 'default');
        $sourceKey = (string) ($this->config['sourceKey'] ?? 'documents');
        $storeAlias = $this->resolveStoreAlias();
        $tenantId = $this->resolveTenantId($state);

        $source = $state->get($sourceKey, []);
        $texts = $this->normalizeTexts($source);
        if ($texts === []) {
            return NodeExecutionResult::success([
                'ingestedCount' => 0,
                'knowledgeBase' => $knowledgeBase,
            ]);
        }

        $result = $this->ingestDispatcher()->ingestTexts($knowledgeBase, $texts, $storeAlias, $tenantId, [
            'runId' => $ctx->runId,
            'nodeId' => $this->nodeId,
        ]);
        $state->set('ingestedCount', $result->documentCount);

        return NodeExecutionResult::success(
            array_merge($result->toArray(), ['ingestedCount' => $result->documentCount]),
            events: ['rag.ingest.completed' => [
                'runId' => $ctx->runId,
                'nodeId' => $this->nodeId,
                'knowledgeBase' => $knowledgeBase,
                'tenantId' => $tenantId,
                'documentCount' => $result->documentCount,
            ]],
            metrics: ['nodeType' => 'rag_ingest', 'documentCount' => $result->documentCount],
        );
    }

    /**
     * 将 state 中的 source 归一化为非空字符串列表。
     *
     * 支持单字符串或字符串数组；空串与非法类型会被过滤。
     *
     * @return list<string>
     */
    private function normalizeTexts(mixed $source): array
    {
        if (is_string($source)) {
            return trim($source) === '' ? [] : [$source];
        }

        if (!is_array($source)) {
            return [];
        }

        $texts = [];
        foreach ($source as $item) {
            if (is_string($item) && trim($item) !== '') {
                $texts[] = $item;
            }
        }

        return $texts;
    }

    /** 当前节点使用的入库调度器；未显式注入时按 neuron_ai.php 配置创建。 */
    private function ingestDispatcher(): RagIngestDispatcher
    {
        return $this->dispatcher ?? RagIngestDispatcher::fromConfig($this->pipeline);
    }

    /**
     * 解析节点级向量库别名。
     *
     * 未配置时返回 null，IngestionPipeline 使用 default_vector_store。
     * 别名须在 rag.vector_stores 声明，否则 VectorStoreFactory 会 fail-fast。
     */
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
