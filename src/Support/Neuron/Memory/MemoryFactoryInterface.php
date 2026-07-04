<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * ChatHistory 工厂契约 —— 按 threadId 解析会话记忆后端。
 *
 * 实现类可选择 Redis 热存储、纯内存或其他自定义后端。
 *
 * @see MemoryFactory
 */
interface MemoryFactoryInterface
{
    /**
     * 为指定会话创建 ChatHistory。
     *
     * @param string $threadId      会话/线程 id
     * @param int    $contextWindow 上下文 token 窗口上限
     */
    public function forThread(string $threadId, int $contextWindow = 50000): ChatHistoryInterface;
}
