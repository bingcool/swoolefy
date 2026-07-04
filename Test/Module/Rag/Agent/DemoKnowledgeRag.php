<?php

declare(strict_types=1);

namespace Test\Module\Rag\Agent;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Swoolefy\Support\Rag\Factory\RagFactory;

/**
 * 演示用知识库 RAG Agent。
 *
 * 注入 RagFactory，按 knowledgeBase + 可选 vector_stores 别名绑定检索链路。
 * Provider 使用 neuron_ai.php 的 default_provider；无凭证时 FakeAIProvider 回退。
 */
final class DemoKnowledgeRag extends RAG
{
    public function __construct(
        private readonly RagFactory $ragFactory,
        private readonly string $knowledgeBase = 'demo_kb',
        private readonly ?string $storeAlias = null,
        private readonly ?int $topK = null,
    ) {
        parent::__construct();
    }

    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            'Based on retrieved knowledge: Swoolefy RAG uses vector_stores aliases and default_vector_store for retrieval.',
        ));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a knowledge assistant for the swoolefy framework.',
                'Answer strictly using retrieved context. If context is insufficient, say so.',
            ],
        );
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->ragFactory->vectorStore($this->knowledgeBase, $this->topK, $this->storeAlias);
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->ragFactory->embeddings();
    }

    protected function retrieval(): RetrievalInterface
    {
        return $this->ragFactory->retrieval($this->knowledgeBase, $this->topK, $this->storeAlias);
    }
}
