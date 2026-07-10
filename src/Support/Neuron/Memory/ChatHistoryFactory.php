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

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\FileChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use PDO;
use RuntimeException;
use Swoolefy\Library\Redis\RedisConnection;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\TenantScope;

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
     * SQL 热存储（整段 messages JSON，按 tenant_id + thread_id 一行）。
     *
     * 使用前须执行 Schema/chat_history.sql。
     */
    public static function sql(
        string $threadId,
        PDO $pdo,
        string $table = 'chat_history',
        int $contextWindow = 50000,
        ?string $tenantId = null,
        ?string $userId = null,
        ?NeuronAiConfig $config = null,
    ): ChatHistoryInterface {
        $config ??= NeuronAiConfig::load();
        $tenant = self::resolveTenantId($tenantId, $config->requireTenantIsolation());
        $user = self::resolveUserId($userId);

        return new SqlChatHistory(
            tenantId: $tenant,
            userId: $user,
            threadId: $threadId,
            pdo: $pdo,
            table: $table,
            contextWindow: $contextWindow,
        );
    }

    /** SQL 冷归档（逐条消息）。使用前须执行 Schema/chat_messages.sql。 */
    public static function archive(
        PDO $pdo,
        string $table = 'chat_messages',
        ?string $tenantId = null,
        ?string $userId = null,
        ?NeuronAiConfig $config = null,
    ): SqlChatHistoryArchive {
        $config ??= NeuronAiConfig::load();
        $tenant = self::resolveTenantId($tenantId, $config->requireTenantIsolation());
        $user = self::resolveUserId($userId);

        return new SqlChatHistoryArchive(
            pdo: $pdo,
            table: $table,
            tenantId: $tenant,
            userId: $user,
        );
    }

    /** Redis 热存储会话记忆（key：chat:{tenantId}:thread:{threadId}）。 */
    public static function redis(
        string $threadId,
        RedisConnection $redis,
        int $contextWindow = 50000,
        ?string $prefix = null,
        int $ttlSeconds = 2592000,
        ?string $tenantId = null,
        ?NeuronAiConfig $config = null,
    ): ChatHistoryInterface {
        $config ??= NeuronAiConfig::load();
        $prefix ??= TenantScope::redisChatKeyPrefix($tenantId, $config->requireTenantIsolation());

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

    private static function resolveTenantId(?string $tenantId, bool $requireTenant): string
    {
        $resolved = TenantScope::resolveTenantId($tenantId);
        if ($resolved === null || $resolved === '') {
            if ($requireTenant) {
                throw new RuntimeException(
                    'tenantId is required for SQL chat history; pass tenantId or set x-tenant-id header',
                );
            }

            return '';
        }

        return TenantScope::sanitize($resolved);
    }

    private static function resolveUserId(?string $userId): string
    {
        if ($userId !== null && $userId !== '') {
            return TenantScope::sanitize($userId);
        }

        $fromContext = FrameworkContext::getUserId();
        if ($fromContext === null || $fromContext === '') {
            return '';
        }

        return TenantScope::sanitize($fromContext);
    }
}
