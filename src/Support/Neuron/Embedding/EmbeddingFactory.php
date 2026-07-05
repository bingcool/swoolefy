<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Embedding;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * Embedding 工厂 —— 生产 OpenAI-like（Swoole HTTP）；无 Key 时 fail-fast（单测可 allow_fake_embeddings）。
 */
final class EmbeddingFactory
{
    public function __construct(
        private readonly ?NeuronAiConfig $config = null,
    ) {
    }

    public function make(): EmbeddingsProviderInterface
    {
        $config = $this->config ?? NeuronAiConfig::load();
        $dimensions = $config->embeddingDimension();
        $apiKey = $this->resolveApiKey($config);

        if ($apiKey === '') {
            if ($config->allowFakeEmbeddings()) {
                return FakeEmbeddingsProvider::make($dimensions);
            }

            throw new WorkflowException(
                'Embedding API key is required. Set OPENAI_API_KEY, configure neuron.ai_model_providers.openai.key, '
                . 'or enable rag.allow_fake_embeddings for local testing only.',
            );
        }

        return new SwooleOpenAILikeEmbeddings(
            baseUri: $this->resolveBaseUri($config),
            key: $apiKey,
            model: $config->embeddingModel(),
            dimensions: $dimensions,
        );
    }

    private function resolveApiKey(NeuronAiConfig $config): string
    {
        $fromEnv = (string) (getenv('OPENAI_API_KEY') ?: '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        foreach ([NeuronAiProviderName::OPENAI, NeuronAiProviderName::OPENAILIKE] as $alias) {
            $section = $config->providerConfig($alias);
            if (!is_array($section)) {
                continue;
            }
            $key = $section['key'] ?? '';
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        return '';
    }

    private function resolveBaseUri(NeuronAiConfig $config): string
    {
        $fromEnv = (string) (getenv('OPENAI_BASE_URI') ?: '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $openAiLike = $config->providerConfig(NeuronAiProviderName::OPENAILIKE);
        if (is_array($openAiLike)) {
            $baseUri = $openAiLike['baseUri'] ?? '';
            if (is_string($baseUri) && $baseUri !== '') {
                return $baseUri;
            }
        }

        return 'https://api.openai.com/v1';
    }
}
