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
     * @param string|null $storeAlias 向量库别名；null 用 default_vector_store
     * @param string|null $tenantId   租户 ID；null 时从 FrameworkContext 读取
     *
     * @return list<array{content: string, score: float, metadata: array<string, mixed>}>
     */
    public function retrieve(
        string $knowledgeBase,
        string $query,
        int $topK = 5,
        ?string $storeAlias = null,
        ?string $tenantId = null,
    ): array {
        $retrieval = $this->ragFactory->retrieval($knowledgeBase, $topK, $storeAlias, $tenantId);
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
