<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Ingestion;

/**
 * RAG 入库队列标准 Job。
 *
 * 这个 DTO 是 Support 模块与业务队列系统之间的稳定契约。Support 不绑定
 * Redis Queue / Kafka / RabbitMQ / DB Job 等具体实现，只要求 producer 与
 * consumer 围绕这个 Job 传递数据。
 *
 * 大批量生产建议：
 * - 队列里只放 sourceRef（DB id / OSS path / file path），不要塞超大文本
 * - consumer 拉取源数据后分批调用 IngestionPipeline::ingestTexts()
 * - tenantId / knowledgeBase / vectorStore 必须显式传递，避免后台任务丢 HTTP 上下文
 */
final class RagIngestJob
{
    /**
     * @param list<string> $texts    小批量文本；大批量建议为空，只传 sourceRef
     * @param array<string, mixed> $metadata 业务元数据，如 userId / traceId / sourceName
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $knowledgeBase,
        public readonly ?string $tenantId = null,
        public readonly ?string $vectorStore = null,
        public readonly string $sourceType = 'text',
        public readonly ?string $sourceRef = null,
        public readonly array $texts = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * 从文本列表创建 Job，过滤空字符串。
     *
     * @param list<string> $texts
     * @param array<string, mixed> $metadata
     */
    public static function fromTexts(
        string $knowledgeBase,
        array $texts,
        ?string $vectorStore = null,
        ?string $tenantId = null,
        array $metadata = [],
    ): self {
        $clean = [];
        foreach ($texts as $text) {
            if (is_string($text) && trim($text) !== '') {
                $clean[] = $text;
            }
        }

        return new self(
            jobId: self::generateJobId(),
            knowledgeBase: $knowledgeBase,
            tenantId: $tenantId,
            vectorStore: $vectorStore,
            sourceType: 'text',
            texts: $clean,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $texts = [];
        $rawTexts = $payload['texts'] ?? [];
        if (is_array($rawTexts)) {
            foreach ($rawTexts as $text) {
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = $text;
                }
            }
        }

        $metadata = $payload['metadata'] ?? [];

        return new self(
            jobId: self::stringOrDefault($payload['jobId'] ?? null, self::generateJobId()),
            knowledgeBase: self::stringOrDefault($payload['knowledgeBase'] ?? null, 'default'),
            tenantId: self::nullableString($payload['tenantId'] ?? null),
            vectorStore: self::nullableString($payload['vectorStore'] ?? $payload['storeAlias'] ?? null),
            sourceType: self::stringOrDefault($payload['sourceType'] ?? null, 'text'),
            sourceRef: self::nullableString($payload['sourceRef'] ?? null),
            texts: $texts,
            metadata: is_array($metadata) ? $metadata : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'jobId' => $this->jobId,
            'tenantId' => $this->tenantId,
            'knowledgeBase' => $this->knowledgeBase,
            'vectorStore' => $this->vectorStore,
            'sourceType' => $this->sourceType,
            'sourceRef' => $this->sourceRef,
            'texts' => $this->texts,
            'metadata' => $this->metadata,
        ];
    }

    private static function generateJobId(): string
    {
        return 'rag_ingest_' . date('Ymd') . '_' . bin2hex(random_bytes(8));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
