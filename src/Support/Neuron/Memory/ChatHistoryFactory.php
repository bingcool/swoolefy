<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\History\SQLChatHistory;
use PDO;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * ChatHistory 构建助手 —— 供 Agent::chatHistory() 选用持久化后端。
 *
 * 业务 Agent 自行实现 chatHistory()，按会话选择 InMemory / SQL / Redis / File。
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 */
final class ChatHistoryFactory
{
    public static function inMemory(int $contextWindow = 50000): ChatHistoryInterface
    {
        return new InMemoryChatHistory($contextWindow);
    }

    /**
 * Neuron 原生 SQL 会话记忆（整段 messages JSON，按 thread_id 一行）。
 *
 * 使用前须先在数据库执行建表脚本：
 *   src/Support/Neuron/Schema/chat_history.sql
 *
 * 表结构见 {@see SQLChatHistory} 与 Schema/chat_history.sql。
     */
    public static function sql(
        string $threadId,
        PDO $pdo,
        string $table = 'chat_history',
        int $contextWindow = 50000,
    ): ChatHistoryInterface {
        return new SQLChatHistory(
            thread_id: $threadId,
            pdo: $pdo,
            table: $table,
            contextWindow: $contextWindow,
        );
    }

    /** Redis 热存储会话记忆。 */
    public static function redis(
        string $threadId,
        RedisConnection $redis,
        int $contextWindow = 50000,
        string $prefix = 'chat:thread:',
        int $ttlSeconds = 2592000,
    ): ChatHistoryInterface {
        return new RedisChatHistory(
            redis: $redis,
            threadId: $threadId,
            contextWindow: $contextWindow,
            prefix: $prefix,
            ttlSeconds: $ttlSeconds,
        );
    }

    /** 文件会话记忆。 */
    public static function file(
        string $directory,
        string $threadId,
        int $contextWindow = 50000,
    ): ChatHistoryInterface {
        return new FileChatHistory(
            directory: $directory,
            key: $threadId,
            contextWindow: $contextWindow,
        );
    }
}
