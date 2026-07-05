<?php

declare(strict_types=1);

namespace Test\Module\Knowledge\Support;

use NeuronAI\RAG\Document;
use Swoolefy\Support\Rag\Factory\RagFactory;

/** 单测/演示用知识库种子数据。 */
final class KnowledgeSeeder
{
    public function __construct(
        private readonly RagFactory $ragFactory,
    ) {
    }

    public function seedProductKb(): void
    {
        $store = $this->ragFactory->vectorStore('product_kb');
        $embedder = $this->ragFactory->embeddings();

        $documents = [
            new Document('Standard interior door frame width is 900mm for residential use.'),
            new Document('Door frame height is typically 2100mm in product catalog 2024.'),
        ];

        $store->addDocuments($embedder->embedDocuments($documents));
    }
}
