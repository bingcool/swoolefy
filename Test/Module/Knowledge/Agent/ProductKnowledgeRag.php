<?php

declare(strict_types=1);

namespace Test\Module\Knowledge\Agent;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\OpenAILike;
use Swoolefy\Support\Rag\Factory\RagFactory;

/**
 * 产品知识库 RAG Agent —— 注入 VectorStore / Embeddings / Retrieval。
 */
final class ProductKnowledgeRag extends RAG
{
    public function __construct(
        private readonly RagFactory $ragFactory,
        private readonly string $knowledgeBase = 'product_kb',
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        $apiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
        if ($apiKey !== '') {
            return new OpenAILike(
                baseUri: (string) (getenv('OPENAI_BASE_URI') ?: 'https://api.openai.com/v1'),
                key: $apiKey,
                model: (string) (getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'),
            );
        }

        return FakeAIProvider::make(new AssistantMessage('Based on product knowledge: standard door frame width is 900mm.'));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: ['You are a product knowledge assistant. Answer using retrieved context.'],
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->ragFactory->vectorStore($this->knowledgeBase);
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->ragFactory->embeddings();
    }

    protected function retrieval(): RetrievalInterface
    {
        return $this->ragFactory->retrieval($this->knowledgeBase);
    }
}
