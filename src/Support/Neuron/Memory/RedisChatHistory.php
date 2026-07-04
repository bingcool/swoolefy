<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use JsonException;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use Swoolefy\Library\Redis\RedisConnection;
use Throwable;

/**
 * Redis 热存储 ChatHistory —— 跨请求保持 LLM 对话上下文。
 *
 * 使用 {@see RedisConnection}（phpredis / predis 均继承该类，经 __call 转发命令）。
 *
 * Redis Key：{prefix}{threadId}（默认 chat:thread:{threadId}）
 * Value：Neuron ChatHistory JSON 序列化
 * TTL：默认 30 天（CHAT_REDIS_TTL）
 *
 * 冷归档至 SQL 由 Phase 2 SqlChatHistoryArchive + goApp() 异步完成。
 *
 * @see docs/swoolefyAI.md §4.7、§6.8
 */
final class RedisChatHistory extends InMemoryChatHistory
{
    private const DEFAULT_PREFIX = 'chat:thread:';

    public function __construct(
        private readonly RedisConnection $redis,
        private readonly string $threadId,
        int $contextWindow = 50000,
        private readonly string $prefix = self::DEFAULT_PREFIX,
        private readonly int $ttlSeconds = 2592000,
    ) {
        parent::__construct($contextWindow);
        $this->hydrateFromRedis();
    }

    /** 新消息写入时持久化到 Redis。 */
    protected function onNewMessage(Message $message): void
    {
        $this->persistToRedis();
    }

    /** 历史截断后同步 Redis。 */
    protected function onTrimHistory(int $index): void
    {
        $this->persistToRedis();
    }

    /** 清空会话时删除 Redis Key。 */
    protected function clear(): void
    {
        try {
            $this->redis->del($this->redisKey());
        } catch (Throwable) {
        }
    }

    /** 启动时从 Redis 加载历史消息。 */
    private function hydrateFromRedis(): void
    {
        try {
            $raw = $this->redis->get($this->redisKey());
        } catch (Throwable) {
            return;
        }

        if (!is_string($raw) || $raw === '') {
            return;
        }

        $messages = json_decode($raw, true);
        if (!is_array($messages)) {
            return;
        }

        $this->history = $this->deserializeMessages($messages);
    }

    /** 将当前 history 序列化写入 Redis。 */
    private function persistToRedis(): void
    {
        try {
            $payload = json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
            if ($this->ttlSeconds > 0) {
                $this->redis->setex($this->redisKey(), $this->ttlSeconds, $payload);
            } else {
                $this->redis->set($this->redisKey(), $payload);
            }
        } catch (Throwable) {
        }
    }

    /** 生成 Redis 存储键。 */
    private function redisKey(): string
    {
        return $this->prefix . $this->threadId;
    }
}
