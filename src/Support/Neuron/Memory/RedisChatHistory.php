<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Support\SupportLog;
use Throwable;

/**
 * Redis 热存储 ChatHistory —— 跨请求保持 LLM 对话上下文。
 *
 * Phase A：Redis 读写失败时 SupportLog::warning 记录，不抛异常，
 * 避免 ChatHistory 故障阻断 Agent 主流程（降级为空历史继续对话）。
 *
 * 使用 {@see RedisConnection}（phpredis / predis 均继承该类，经 __call 转发命令）。
 *
 * Redis Key：{prefix}{threadId}（默认 chat:thread:{threadId}）
 * Value：Neuron ChatHistory JSON 序列化
 * TTL：默认 30 天（CHAT_REDIS_TTL）
 *
 * 供 Agent::chatHistory() 返回；也可经 {@see ChatHistoryFactory::redis()} 创建。
 *
 * @see docs/swoolefyAI.md §4.7、§6.8
 */
final class RedisChatHistory extends InMemoryChatHistory implements HotChatHistoryInterface
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

    /** {@inheritdoc} */
    public function threadId(): string
    {
        return $this->threadId;
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
            if ($this->redis instanceof \Swoolefy\Library\Redis\Redis ||
                $this->redis instanceof \Swoolefy\Library\Redis\RedisCluster) {
                $this->redis->del($this->redisKey());
            } else {
                $this->redis->del([$this->redisKey()]);
            }
        } catch (Throwable $e) {
            // Phase A：清除失败打日志，不向上抛（会话仍可继续，仅 Redis 残留）
            SupportLog::warning('chat_history', 'Failed to clear Redis chat history', [
                'threadId' => $this->threadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** 启动时从 Redis 加载历史消息。 */
    private function hydrateFromRedis(): void
    {
        try {
            $raw = $this->redis->get($this->redisKey());
        } catch (Throwable $e) {
            // hydrate 失败：降级为空历史，Agent 仍可发起新对话
            SupportLog::warning('chat_history', 'Failed to hydrate Redis chat history', [
                'threadId' => $this->threadId,
                'error' => $e->getMessage(),
            ]);

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
        } catch (Throwable $e) {
            // persist 失败：内存 history 仍有效，仅跨请求无法恢复
            SupportLog::warning('chat_history', 'Failed to persist Redis chat history', [
                'threadId' => $this->threadId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** 生成 Redis 存储键。 */
    private function redisKey(): string
    {
        return $this->prefix . $this->threadId;
    }
}
