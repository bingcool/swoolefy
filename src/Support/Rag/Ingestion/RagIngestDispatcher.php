<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Ingestion;

use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * RAG 入库调度器。
 *
 * mode=sync：保持旧行为，当前 Worker 内同步 embed + 写 VectorStore。
 * mode=queue：将标准 RagIngestJob 交给配置化 producer，由后台 consumer 调用入库。
 *
 * 这样 HTTP / Workflow 入口只负责提交任务，不绑定队列实现；消费侧复用同一
 * IngestionPipeline，确保 Embedding / VectorStore / tenantId 规则一致。
 */
final class RagIngestDispatcher
{
    public const MODE_SYNC = 'sync';
    public const MODE_QUEUE = 'queue';

    public function __construct(
        private readonly IngestionPipeline $pipeline,
        private readonly string $mode = self::MODE_SYNC,
        private readonly ?ConfigurableRagIngestQueue $queue = null,
    ) {
    }

    public static function fromConfig(IngestionPipeline $pipeline, ?NeuronAiConfig $config = null): self
    {
        $config ??= NeuronAiConfig::load();
        $ingestion = (array) (($config->ragSection()['ingestion'] ?? []));
        $mode = strtolower((string) ($ingestion['mode'] ?? self::MODE_SYNC));
        $mode = in_array($mode, [self::MODE_SYNC, self::MODE_QUEUE], true) ? $mode : self::MODE_SYNC;
        $queue = $ingestion['queue'] ?? [];

        return new self(
            pipeline: $pipeline,
            mode: $mode,
            queue: $mode === self::MODE_QUEUE
                ? new ConfigurableRagIngestQueue(is_array($queue) ? $queue : [])
                : null,
        );
    }

    /**
     * 入库文本：同步模式直接写入；队列模式返回 queued 结果。
     *
     * @param list<string> $texts
     * @param array<string, mixed> $metadata
     */
    public function ingestTexts(
        string $knowledgeBase,
        array $texts,
        ?string $storeAlias = null,
        ?string $tenantId = null,
        array $metadata = [],
    ): IngestResult {
        $job = RagIngestJob::fromTexts($knowledgeBase, $texts, $storeAlias, $tenantId, $metadata);
        if ($job->texts === []) {
            return new IngestResult(0, $knowledgeBase);
        }

        if ($this->mode === self::MODE_QUEUE) {
            $this->queue()->dispatch($job);

            return new IngestResult(0, $knowledgeBase, status: 'queued', jobId: $job->jobId);
        }

        return $this->pipeline->ingestTexts($knowledgeBase, $job->texts, $storeAlias, $tenantId);
    }

    /** 消费端入口：从队列 payload 恢复 Job 后执行配置的 consumer。 */
    public function consume(RagIngestJob $job): IngestResult
    {
        return $this->queue()->consume($job, $this->pipeline);
    }

    private function queue(): ConfigurableRagIngestQueue
    {
        if ($this->queue === null) {
            throw new \RuntimeException('RAG ingest queue is not configured');
        }

        return $this->queue;
    }
}
