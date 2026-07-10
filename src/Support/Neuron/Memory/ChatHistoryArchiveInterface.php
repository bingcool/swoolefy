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

namespace Swoolefy\Support\Neuron\Memory;

/**
 * ChatHistory 冷归档契约 —— 热存储之外的永久持久化（SQL / 对象存储等）。
 *
 * @see SqlChatHistoryArchive
 */
interface ChatHistoryArchiveInterface
{
    /**
     * 归档单条消息。
     *
     * @param array<string, mixed> $metadata
     */
    public function archiveMessage(string $threadId, string $role, string $content, array $metadata = []): void;

    /**
     * 批量归档。
     *
     * @param list<array{role: string, content: string, metadata?: array<string, mixed>}> $messages
     */
    public function archiveBatch(string $threadId, array $messages): void;

    /**
     * 按 threadId 读取历史消息（按时间正序，供多轮会话回放）。
     *
     * @return list<array{role: string, content: string, metadata: array<string, mixed>}>
     */
    public function listMessages(string $threadId, int $limit = 50): array;
}
