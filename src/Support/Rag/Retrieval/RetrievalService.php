<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Retrieval;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use Swoolefy\Support\Rag\Factory\RagFactory;

/**
 * 检索服务 —— 将 SimilarityRetrieval 结果格式化为 WorkflowState 可用的数组。
 */
final class RetrievalService
{
    public function __construct(
        private readonly RagFactory $ragFactory,
    ) {
    }

    /**
     * @return list<array{content: string, score: float, metadata: array<string, mixed>}>
     */
    public function retrieve(string $knowledgeBase, string $query, int $topK = 5): array
    {
        $retrieval = $this->ragFactory->retrieval($knowledgeBase, $topK);
        /** @var list<Document> $documents */
        $documents = $retrieval->retrieve(new UserMessage($query));

        $formatted = [];
        foreach ($documents as $document) {
            $formatted[] = [
                'content' => $document->getContent(),
                'score' => $document->score,
                'metadata' => $document->metadata,
            ];
        }

        return $formatted;
    }
}
