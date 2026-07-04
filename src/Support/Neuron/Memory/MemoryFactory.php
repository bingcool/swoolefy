<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Redis;

/**
 * ChatHistory 工厂 —— 按 threadId 创建会话记忆后端。
 *
 * threadId 策略（见 docs/swoolefyAI.md §4.7）：
 *   {userId}:{agentName}           — 用户长期记忆
 *   {sessionId}                    — 匿名会话
 *   {userId}:{workflowId}:{runId}  — 单次 Run 隔离
 *
 * 无 Redis 时回退 InMemoryChatHistory（单进程有效）。
 */
final class MemoryFactory
{
    public function __construct(
        private readonly ?Redis $redis = null,
        private readonly string $prefix = 'chat:thread:',
        private readonly int $ttlSeconds = 2592000,
    ) {
    }

    /**
     * 为指定会话创建 ChatHistory。
     *
     * @param string $threadId      会话/线程 id
     * @param int    $contextWindow 上下文 token 窗口上限
     */
    public function forThread(string $threadId, int $contextWindow = 50000): ChatHistoryInterface
    {
        if ($this->redis !== null) {
            return new RedisChatHistory(
                redis: $this->redis,
                threadId: $threadId,
                contextWindow: $contextWindow,
                prefix: $this->prefix,
                ttlSeconds: $this->ttlSeconds,
            );
        }

        return new InMemoryChatHistory($contextWindow);
    }
}
