<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Factory;

use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Swoolefy\Support\Rag\Store\MeilisearchConfig;

/**
 * 向量存储工厂 —— 按环境切换 file / meilisearch，按 knowledgeBase 隔离索引。
 *
 * 技术要点：
 * - 开发/单测：FileVectorStore，零外部依赖，目录 {basePath}/{knowledgeBase}/
 * - 生产：MeilisearchVectorStore，每知识库独立 indexUid
 * - knowledgeBase 名经 sanitize 后作为 index/目录名，防止路径注入
 *
 * 环境变量：
 *   RAG_VECTOR_STORE=file|meilisearch
 *   RAG_FILE_STORE_PATH、RAG_DEFAULT_TOP_K
 *   MEILISEARCH_HOST、MEILISEARCH_KEY（meilisearch 模式）
 *
 * @see swoolefyAI.md §4.10.2
 */
final class VectorStoreFactory
{
    /**
     * @param string               $basePath    File 模式根目录
     * @param int                  $defaultTopK 默认检索 TopK
     * @param string               $storeType   file | meilisearch
     * @param MeilisearchConfig|null $meilisearch meilisearch 模式连接配置
     */
    public function __construct(
        private readonly string $basePath,
        private readonly int $defaultTopK = 5,
        private readonly string $storeType = 'file',
        private readonly ?MeilisearchConfig $meilisearch = null,
    ) {
    }

    /**
     * 从环境变量构建工厂（推荐 HTTP / CLI 入口使用）。
     *
     * RAG_VECTOR_STORE=meilisearch 时自动加载 MeilisearchConfig::fromEnv()
     */
    public static function fromEnv(?string $basePath = null): self
    {
        $path = $basePath ?? (string) (getenv('RAG_FILE_STORE_PATH') ?: sys_get_temp_dir() . '/swoolefy_rag');
        $storeType = (string) (getenv('RAG_VECTOR_STORE') ?: 'file');
        $topK = (int) (getenv('RAG_DEFAULT_TOP_K') ?: 5);

        return new self(
            basePath: $path,
            defaultTopK: $topK,
            storeType: $storeType,
            meilisearch: $storeType === 'meilisearch' ? MeilisearchConfig::fromEnv() : null,
        );
    }

    /**
     * 获取指定知识库的 VectorStore 实例。
     *
     * 每次调用返回新实例（无进程级单例），协程安全由调用方 Context 管理。
     */
    public function make(string $knowledgeBase, ?int $topK = null): VectorStoreInterface
    {
        $index = $this->sanitize($knowledgeBase);
        $k = $topK ?? $this->defaultTopK;

        if ($this->storeType === 'meilisearch' && $this->meilisearch !== null) {
            // Neuron MeilisearchVectorStore：index 不存在时自动 createIndex
            return new MeilisearchVectorStore(
                indexUid: $index,
                host: $this->meilisearch->host,
                key: $this->meilisearch->apiKey,
                embedder: $this->meilisearch->embedder,
                topK: $k,
                dimension: $this->meilisearch->dimension,
            );
        }

        $directory = rtrim($this->basePath, '/') . '/' . $index;

        return new FileVectorStore(
            directory: $directory,
            topK: $k,
            name: $index,
        );
    }

    /** 当前 store 类型（file / meilisearch），供观测与单测断言。 */
    public function storeType(): string
    {
        return $this->storeType;
    }

    /** 知识库名消毒：仅保留字母数字 _ -，防止目录穿越。 */
    private function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?: 'default';
    }
}
