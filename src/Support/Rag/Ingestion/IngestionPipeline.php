<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Ingestion;

use NeuronAI\RAG\Document;
use Swoolefy\Support\Rag\Factory\RagFactory;

/**
 * 文档入库管线 —— 统一 embed → vectorStore.addDocuments 流程。
 *
 * 技术要点：
 * - 入库与检索共用 {@see RagFactory}，保证 VectorStore / Embeddings 一致
 * - embedDocuments 由 Neuron EmbeddingsProvider 批量向量化（内部逐条 embedDocument）
 * - 大批量场景应走 {@see RagIngestNode} + AsyncTask，避免阻塞 Swoole Worker
 * - RAG 上下文写入 VectorStore，不写入 ChatHistory（与 Memory 分离）
 *
 * @see swoolefyAI.md §4.10.5
 */
final class IngestionPipeline
{
    public function __construct(
        private readonly RagFactory $ragFactory,
    ) {
    }

    /**
     * 将 Document 列表向量化并写入指定知识库。
     *
     * @param string         $knowledgeBase 知识库名称（映射为 index / 目录）
     * @param list<Document> $documents     待入库文档（content 必填）
     */
    public function ingest(string $knowledgeBase, array $documents): IngestResult
    {
        if ($documents === []) {
            return new IngestResult(0, $knowledgeBase);
        }

        // 1. 向量化：Neuron 会在 Document 上写入 embedding 字段
        $embedder = $this->ragFactory->embeddings();
        $embedded = $embedder->embedDocuments($documents);

        // 2. 持久化：FileVectorStore 写本地目录；MeilisearchVectorStore 调 HTTP API
        $this->ragFactory->vectorStore($knowledgeBase)->addDocuments($embedded);

        return new IngestResult(count($embedded), $knowledgeBase);
    }

    /**
     * 从纯文本列表快速入库（CLI / 单测 / HTTP 上传简化路径）。
     *
     * @param string       $knowledgeBase 目标知识库
     * @param list<string> $contents      文本内容列表
     */
    public function ingestTexts(string $knowledgeBase, array $contents): IngestResult
    {
        $documents = [];
        foreach ($contents as $content) {
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            $documents[] = new Document($content);
        }

        return $this->ingest($knowledgeBase, $documents);
    }
}
