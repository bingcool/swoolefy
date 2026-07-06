<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Ingestion;

/**
 * 入库结果摘要 —— 供 RagIngestNode output / HTTP API 响应使用。
 */
final class IngestResult
{
    /**
     * @param int    $documentCount 成功写入的文档数
     * @param string $knowledgeBase 目标知识库名称
     */
    public function __construct(
        public readonly int $documentCount,
        public readonly string $knowledgeBase,
    ) {
    }

    /** 序列化为 state.data / JSON 响应结构。 */
    public function toArray(): array
    {
        return [
            'documentCount' => $this->documentCount,
            'knowledgeBase' => $this->knowledgeBase,
        ];
    }
}
