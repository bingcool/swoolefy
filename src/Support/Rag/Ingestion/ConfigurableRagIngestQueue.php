<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Ingestion;

use RuntimeException;

/**
 * 配置化 RAG 入库队列适配器。
 *
 * Support 模块只定义 producer / consumer 的调用约定，不绑定具体队列。
 *
 * producer:
 *   class  => App\Queue\RagIngestProducer::class
 *   method => push
 *   签名建议：push(RagIngestJob $job): void
 *
 * consumer:
 *   class  => App\Queue\RagIngestConsumer::class
 *   method => handle
 *   签名建议：handle(RagIngestJob $job, IngestionPipeline $pipeline): IngestResult
 */
final class ConfigurableRagIngestQueue
{
    /** @param array<string, mixed> $config rag.ingestion.queue */
    public function __construct(
        private readonly array $config,
    ) {
    }

    /** 将 Job 交给业务 producer 写入队列。 */
    public function dispatch(RagIngestJob $job): void
    {
        $this->callConfigured(
            $this->target('producer'),
            [$job],
            'rag.ingestion.queue.producer',
        );
    }

    /**
     * 消费端处理 Job。
     *
     * 业务 consumer 可在这里解析 sourceRef、分页读取大文本，再调用 pipeline。
     */
    public function consume(RagIngestJob $job, IngestionPipeline $pipeline): IngestResult
    {
        $result = $this->callConfigured(
            $this->target('consumer'),
            [$job, $pipeline],
            'rag.ingestion.queue.consumer',
        );

        if ($result instanceof IngestResult) {
            return $result;
        }

        // 允许业务 consumer 只负责副作用不返回结果；这里给出最小完成摘要。
        return new IngestResult(count($job->texts), $job->knowledgeBase);
    }

    /** @return array{class: string, method: string} */
    private function target(string $key): array
    {
        $target = $this->config[$key] ?? [];
        if (!is_array($target)) {
            throw new RuntimeException("RAG ingest queue {$key} config must be an array");
        }

        $class = $target['class'] ?? null;
        $method = $target['method'] ?? null;
        if (!is_string($class) || $class === '' || !is_string($method) || $method === '') {
            throw new RuntimeException("RAG ingest queue {$key} requires class and method");
        }

        return ['class' => $class, 'method' => $method];
    }

    /**
     * @param array{class: string, method: string} $target
     * @param list<mixed> $args
     */
    private function callConfigured(array $target, array $args, string $label): mixed
    {
        $class = $target['class'];
        $method = $target['method'];

        if (!class_exists($class)) {
            throw new RuntimeException("{$label} class not found: {$class}");
        }

        $handler = new $class();
        if (!method_exists($handler, $method)) {
            throw new RuntimeException("{$label} method not found: {$class}::{$method}");
        }

        return $handler->{$method}(...$args);
    }
}
