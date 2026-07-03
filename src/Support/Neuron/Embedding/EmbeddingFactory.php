<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Embedding;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use Swoolefy\Support\Neuron\Embedding\SwooleOpenAILikeEmbeddings;
use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * Embedding 工厂 —— 生产 OpenAI-like（Swoole HTTP）；无 Key 时 FakeEmbeddings。
 */
final class EmbeddingFactory
{
    public function make(): EmbeddingsProviderInterface
    {
        $apiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
        if ($apiKey !== '') {
            return new SwooleOpenAILikeEmbeddings(
                baseUri: (string) (getenv('OPENAI_BASE_URI') ?: 'https://api.openai.com/v1'),
                key: $apiKey,
                model: NeuronAiConfig::load()->embeddingModel(),
            );
        }

        return FakeEmbeddingsProvider::make(64);
    }
}
