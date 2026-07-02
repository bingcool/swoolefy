<?php

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

        $query = (string) $state->get($queryKey, '');
        $docs = $query === ''
            ? []
            : $this->retrievalService->retrieve($knowledgeBase, $query, $topK);

        $state->set($outputKey, $docs);

        return NodeExecutionResult::success(
            [$outputKey => $docs],
            events: ['rag.retrieved' => [
                'runId' => $ctx->runId,
                'nodeId' => $this->nodeId,
                'knowledgeBase' => $knowledgeBase,
                'docCount' => count($docs),
            ]],
            metrics: ['nodeType' => 'rag_retrieve', 'docCount' => count($docs)],
        );
    }
}
