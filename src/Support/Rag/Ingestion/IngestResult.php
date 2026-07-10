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

/**
 * 入库结果摘要 —— 供 RagIngestNode output / HTTP API 响应使用。
 */
final class IngestResult
{
    /**
     * @param int    $documentCount 成功写入的文档数
     * @param string $knowledgeBase 目标知识库名称
     * @param string $status        sync: completed；queue: queued
     * @param string|null $jobId    队列模式下的 Job ID
     */
    public function __construct(
        public readonly int $documentCount,
        public readonly string $knowledgeBase,
        public readonly string $status = 'completed',
        public readonly ?string $jobId = null,
    ) {
    }

    /** 序列化为 state.data / JSON 响应结构。 */
    public function toArray(): array
    {
        $payload = [
            'documentCount' => $this->documentCount,
            'knowledgeBase' => $this->knowledgeBase,
            'status' => $this->status,
        ];
        if ($this->jobId !== null) {
            $payload['jobId'] = $this->jobId;
        }

        return $payload;
    }
}
