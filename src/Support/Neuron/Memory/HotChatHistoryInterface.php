<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;

/**
 * 热路径会话记忆契约 —— 跨请求持久化的 ChatHistory（如 Redis）。
 *
 * 继承 Neuron {@see ChatHistoryInterface}，可直接交给 Agent::setChatHistory()。
 * 冷归档见 {@see ChatHistoryArchiveInterface}。
 *
 * @see RedisChatHistory
 */
interface HotChatHistoryInterface extends ChatHistoryInterface
{
    /** 会话线程 id（与 Redis key / 归档 thread_id 对齐）。 */
    public function threadId(): string;
}
