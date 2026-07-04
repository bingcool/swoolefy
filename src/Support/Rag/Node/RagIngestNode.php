<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Node;

use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\StringDocumentLoader;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * RAG 文档入库工作流节点 —— 从 WorkflowState 读取文本并写入 VectorStore。
 *
 * 三种运行模式：
 *   1. 空 source → 返回 ingestedCount=0，不报错
 *   2. 同步入库 → 调用 IngestionPipeline::ingest（单测 / 小批量）
 *   3. 异步排队 → 文档数 ≥ asyncThreshold 且 RAG_INGEST_ASYNC=1，返回 ingestJobId
 *
 * 配置项：
 *   knowledgeBase   — 目标知识库（VectorStore index 名）
 *   sourceKey       — state.data 键，值为 string 或 list<string>
 *   asyncThreshold  — 触发异步模式的文档数阈值，默认 100
 *
 * 对外事件：
 *   rag.ingest.queued   — 异步模式
 *   rag.ingest.completed — 同步完成
 *
 * @see docs/swoolefyAI.md §4.10.5
 */
final class RagIngestNode extends AbstractNode
{
    /**
     * @param array<string, mixed> $config   节点配置
     * @param IngestionPipeline    $pipeline 入库管线（依赖注入，便于单测 mock）
     */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly IngestionPipeline $pipeline,
    ) {
        parent::__construct($nodeId);
    }

    /**
     * 执行入库：读 sourceKey → 归一化文本 → 同步/异步分支。
     */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $knowledgeBase = (string) ($this->config['knowledgeBase'] ?? 'default');
        $sourceKey = (string) ($this->config['sourceKey'] ?? 'documents');
        $asyncThreshold = (int) ($this->config['asyncThreshold'] ?? 100);

        $source = $state->get($sourceKey, []);
        $texts = $this->normalizeTexts($source);
        if ($texts === []) {
            return NodeExecutionResult::success([
                'ingestedCount' => 0,
                'knowledgeBase' => $knowledgeBase,
            ]);
        }

        // 大批量 + 异步开关：仅返回 jobId，实际入库由 AsyncTask Worker 完成（Phase 4 预留扩展点）
        if (count($texts) >= $asyncThreshold && $this->shouldDeferAsync()) {
            $jobId = 'ingest_' . bin2hex(random_bytes(6));
            $state->set('ingestJobId', $jobId);

            return NodeExecutionResult::success([
                'ingestJobId' => $jobId,
                'async' => true,
                'pendingCount' => count($texts),
                'knowledgeBase' => $knowledgeBase,
            ], events: ['rag.ingest.queued' => [
                'runId' => $ctx->runId,
                'jobId' => $jobId,
                'knowledgeBase' => $knowledgeBase,
            ]]);
        }

        $documents = StringDocumentLoader::fromTexts($texts);
        $result = $this->pipeline->ingest($knowledgeBase, $documents);
        $state->set('ingestedCount', $result->documentCount);

        return NodeExecutionResult::success(
            array_merge($result->toArray(), ['ingestedCount' => $result->documentCount]),
            events: ['rag.ingest.completed' => [
                'runId' => $ctx->runId,
                'nodeId' => $this->nodeId,
                'knowledgeBase' => $knowledgeBase,
                'documentCount' => $result->documentCount,
            ]],
            metrics: ['nodeType' => 'rag_ingest', 'documentCount' => $result->documentCount],
        );
    }

    /**
     * 将 state 中的 source 归一化为非空字符串列表。
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

    /**
     * 是否走异步入库路径。
     *
     * 环境变量 RAG_INGEST_ASYNC=1 启用；CLI 单测默认同步以保证可重复断言。
     */
    private function shouldDeferAsync(): bool
    {
        return filter_var(getenv('RAG_INGEST_ASYNC') ?: '0', FILTER_VALIDATE_BOOLEAN);
    }
}
