<?php

declare(strict_types=1);

namespace Test\Module\Rag;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Test\Module\Rag\Agent\DemoKnowledgeRag;

/**
 * Rag 模块服务层 —— 封装入库、检索、问答与配置探查。
 *
 * 与 WorkflowService 共用同一套 neuron_ai.php 配置，保证 Embedding / VectorStore 一致。
 * 默认知识库：demo_kb；默认向量库别名：rag.default_vector_store。
 *
 * @see \Test\Module\Rag\Controller\RagController
 * @see \Test\Module\Rag\README.md
 */
final class RagService
{
    public const DEFAULT_KNOWLEDGE_BASE = 'demo_kb';

    private static ?self $instance = null;

    private function __construct(
        private readonly NeuronAiConfig $config,
        private readonly RagFactory $ragFactory,
        private readonly RetrievalService $retrievalService,
        private readonly IngestionPipeline $ingestionPipeline,
    ) {
    }

    /** 单例（HTTP 演示用）；单测可 {@see reset()} / {@see boot()}。 */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::boot(NeuronAiConfig::load());
        }

        return self::$instance;
    }

    /**
     * 使用指定配置启动（单测 / CLI 注入）。
     */
    public static function boot(NeuronAiConfig $config): self
    {
        $ragFactory = new RagFactory(new VectorStoreFactory($config), new EmbeddingFactory());
        self::$instance = new self(
            $config,
            $ragFactory,
            new RetrievalService($ragFactory),
            $ragFactory->ingestionPipeline(),
        );

        return self::$instance;
    }

    /** @internal 单测隔离 */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * 当前 RAG 配置摘要（不含密钥明文）。
     *
     * @return array<string, mixed>
     */
    public function configSummary(): array
    {
        $stores = [];
        foreach ($this->config->vectorStores() as $alias => $section) {
            $driver = $section['driver'] ?? $alias;
            $stores[] = [
                'alias' => $alias,
                'driver' => is_string($driver) ? $driver : (string) $alias,
                'isDefault' => $alias === $this->config->defaultVectorStoreAlias(),
            ];
        }

        return [
            'defaultVectorStore' => $this->config->defaultVectorStoreAlias(),
            'defaultDriver' => $this->config->vectorStoreDriver(),
            'defaultTopK' => $this->config->defaultTopK(),
            'embeddingModel' => $this->config->embeddingModel(),
            'defaultKnowledgeBase' => self::DEFAULT_KNOWLEDGE_BASE,
            'vectorStores' => $stores,
        ];
    }

    /**
     * 已声明的向量库别名列表。
     *
     * @return list<array<string, mixed>>
     */
    public function listStores(): array
    {
        return $this->configSummary()['vectorStores'];
    }

    /**
     * 文本入库。
     *
     * @param list<string> $texts
     * @return array<string, mixed>
     */
    public function ingest(
        string $knowledgeBase,
        array $texts,
        ?string $storeAlias = null,
    ): array {
        $result = $this->ingestionPipeline->ingestTexts($knowledgeBase, $texts, $storeAlias);

        return [
            'knowledgeBase' => $result->knowledgeBase,
            'documentCount' => $result->documentCount,
            'storeAlias' => $storeAlias ?? $this->config->defaultVectorStoreAlias(),
            'storeDriver' => $this->config->vectorStoreDriver($storeAlias),
        ];
    }

    /**
     * 写入演示语料（Swoolefy / RAG / Workflow 相关），便于本地检索演示。
     *
     * @return array<string, mixed>
     */
    public function seed(string $knowledgeBase = self::DEFAULT_KNOWLEDGE_BASE, ?string $storeAlias = null): array
    {
        $texts = [
            'Swoolefy is a PHP coroutine framework based on Swoole, designed for high-concurrency HTTP and RPC services.',
            'RAG (Retrieval-Augmented Generation) retrieves relevant documents from a vector store before the LLM answers.',
            'In swoolefy, VectorStoreFactory resolves rag.vector_stores aliases; default_vector_store selects the default alias.',
            'Supported vector store drivers include file, meilisearch, phpvector, mariadb, pinecone, qdrant and milvus.',
            'File vector store keeps embeddings under {path}/{knowledgeBase}/ for local development without external services.',
            'Workflow module can register knowledge_qa which runs RagRetrieveNode then RAGNode for product Q&A.',
            'Order processing workflow uses AI risk decision with confidence thresholds for payment, manual review or reject.',
            'Saga compensation runs compensate() in reverse order of executed nodes when a later node fails.',
            'Neuron AI agents support structured output, tools, vision, streaming SSE and SQL chat history persistence.',
            'MCP research workflow declares github and brave_search tools, then routes urgent summaries to notify.',
        ];

        return $this->ingest($knowledgeBase, $texts, $storeAlias);
    }

    /**
     * 相似度检索。
     *
     * @return array<string, mixed>
     */
    public function retrieve(
        string $knowledgeBase,
        string $query,
        ?int $topK = null,
        ?string $storeAlias = null,
    ): array {
        $k = $topK ?? $this->config->defaultTopK();
        $hits = $this->retrievalService->retrieve($knowledgeBase, $query, $k, $storeAlias);

        return [
            'knowledgeBase' => $knowledgeBase,
            'query' => $query,
            'topK' => $k,
            'storeAlias' => $storeAlias ?? $this->config->defaultVectorStoreAlias(),
            'storeDriver' => $this->config->vectorStoreDriver($storeAlias),
            'hitCount' => count($hits),
            'hits' => $hits,
        ];
    }

    /**
     * 检索增强问答。
     *
     * - useAgent=false（默认）：基于检索片段做抽取式回答（离线可用）
     * - useAgent=true：走 DemoKnowledgeRag（有 API Key 用真实模型，否则 Fake）
     *
     * @return array<string, mixed>
     */
    public function ask(
        string $knowledgeBase,
        string $query,
        ?int $topK = null,
        ?string $storeAlias = null,
        bool $useAgent = false,
    ): array {
        $retrieval = $this->retrieve($knowledgeBase, $query, $topK, $storeAlias);
        $hits = $retrieval['hits'];

        if ($useAgent) {
            $agent = new DemoKnowledgeRag(
                $this->ragFactory,
                $knowledgeBase,
                $storeAlias,
                $topK ?? $this->config->defaultTopK(),
            );
            $answer = $agent->chat(new UserMessage($query))->getMessage()->getContent();
            $mode = 'agent';
        } else {
            $answer = $this->extractiveAnswer($query, $hits);
            $mode = 'extractive';
        }

        return [
            'knowledgeBase' => $knowledgeBase,
            'query' => $query,
            'answer' => $answer,
            'mode' => $mode,
            'storeAlias' => $retrieval['storeAlias'],
            'storeDriver' => $retrieval['storeDriver'],
            'topK' => $retrieval['topK'],
            'hitCount' => $retrieval['hitCount'],
            'hits' => $hits,
        ];
    }

    public function ragFactory(): RagFactory
    {
        return $this->ragFactory;
    }

    public function retrievalService(): RetrievalService
    {
        return $this->retrievalService;
    }

    public function config(): NeuronAiConfig
    {
        return $this->config;
    }

    /**
     * 无 LLM 时的抽取式回答：拼接 Top 命中片段。
     *
     * @param list<array{content: string, score: float, metadata: array<string, mixed>}> $hits
     */
    private function extractiveAnswer(string $query, array $hits): string
    {
        if ($hits === []) {
            return 'No relevant documents found for: ' . $query;
        }

        $parts = [];
        foreach ($hits as $i => $hit) {
            $content = trim((string) ($hit['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $score = isset($hit['score']) ? number_format((float) $hit['score'], 4) : 'n/a';
            $parts[] = '[' . ($i + 1) . '] (score=' . $score . ') ' . $content;
        }

        if ($parts === []) {
            return 'No relevant documents found for: ' . $query;
        }

        return "Based on retrieved context for \"{$query}\":\n\n" . implode("\n\n", $parts);
    }
}
