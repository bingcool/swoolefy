<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Embedding;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Workflow\Exception\WorkflowException;

/**
 * Embedding 提供者工厂。
 *
 * 生产环境须配置 API Key；无 Key 且 allow_fake_embeddings=false 时 fail-fast，
 * 避免 RAG 入库/检索在运行时静默使用错误维度向量。
 *
 * API Key 解析顺序：
 *   1. 环境变量 OPENAI_API_KEY
 *   2. neuron.ai_model_providers.openai.key
 *   3. neuron.ai_model_providers.openailike.key
 *
 * baseUri 解析顺序：
 *   1. OPENAI_BASE_URI
 *   2. openailike.baseUri
 *   3. 默认 https://api.openai.com/v1
 *
 * 向量维度统一读取 rag.embedding_dimension，须与各 vector_stores.*.dimension 一致。
 *
 * @see NeuronAiConfig::embeddingDimension()
 * @see ProductionHealthCheck::checkNeuron()
 */
final class EmbeddingFactory
{
    public function __construct(
        private readonly ?NeuronAiConfig $config = null,
    ) {
    }

    /**
     * 创建 EmbeddingsProvider 实例。
     *
     * @throws WorkflowException 无 API Key 且未启用 allow_fake_embeddings
     */
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

    /** 从 env / neuron 配置解析 Embedding API Key。 */
    private function resolveApiKey(NeuronAiConfig $config): string
    {
        $fromEnv = env(NeuronAiModelEnv::OPENAI_API_KEY, '');
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

    /** 解析 OpenAI-compatible Embedding API baseUri。 */
    private function resolveBaseUri(NeuronAiConfig $config): string
    {
        $fromEnv = env(NeuronAiModelEnv::OPENAILIKE_BASE_URI, '');
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
