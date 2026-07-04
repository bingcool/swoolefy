<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

/**
 * ChatHistory 工厂 —— 按 threadId 创建进程内会话记忆。
 *
 * threadId 策略（见 docs/swoolefyAI.md §4.7）：
 *   {userId}:{agentName}           — 用户长期记忆
 *   {sessionId}                    — 匿名会话
 *   {userId}:{workflowId}:{runId}  — 单次 Run 隔离
 *
 * 默认使用 {@see InMemoryChatHistory}（单进程有效）。
 * 跨请求热存储请直接使用 {@see RedisChatHistory}，或自定义 {@see MemoryFactoryInterface}。
 */
final class MemoryFactory implements MemoryFactoryInterface
{
    /** {@inheritdoc} */
    public function forThread(string $threadId, int $contextWindow = 50000): ChatHistoryInterface
    {
        return new InMemoryChatHistory($contextWindow);
    }
}
