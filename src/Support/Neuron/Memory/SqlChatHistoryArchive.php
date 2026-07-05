<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use PDO;
use PDOException;

/**
 * ChatHistory SQL 冷归档 —— 永久持久化多轮会话（逐条消息一行）。
 *
 * 使用前须先在数据库执行建表脚本：
 *   src/Support/Neuron/Schema/chat_messages.sql
 *
 * 与 {@see SqlChatHistory}（表 chat_history，整段 JSON 热存储）配合使用。
 *
 * @see Schema/chat_messages.sql
 * @see Schema/chat_history.sql
 */
final class SqlChatHistoryArchive implements ChatHistoryArchiveInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chat_messages',
        private readonly string $tenantId = '',
        private readonly string $userId = '',
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** {@inheritdoc} */
    public function archiveMessage(string $threadId, string $role, string $content, array $metadata = []): void
    {
        $sql = sprintf(
            'INSERT INTO %s (tenant_id, user_id, thread_id, role, content, metadata_json, created_at, updated_at)
             VALUES (:tenant_id, :user_id, :thread_id, :role, :content, :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
                'thread_id' => $threadId,
                'role' => $role,
                'content' => $content,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        } catch (PDOException) {
            $this->archiveMessageSimple($threadId, $role, $content);
        }
    }

    /** {@inheritdoc} */
    public function archiveBatch(string $threadId, array $messages): void
    {
        foreach ($messages as $message) {
            $this->archiveMessage(
                $threadId,
                (string) ($message['role'] ?? 'unknown'),
                (string) ($message['content'] ?? ''),
                is_array($message['metadata'] ?? null) ? $message['metadata'] : [],
            );
        }
    }

    /** {@inheritdoc} */
    public function listMessages(string $threadId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $sql = sprintf(
            'SELECT role, content, metadata_json FROM %s
             WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NULL
             ORDER BY id ASC LIMIT %d',
            $this->table,
            $limit,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'thread_id' => $threadId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            $sql = sprintf(
                'SELECT role, content FROM %s
                 WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NULL
                 ORDER BY id ASC LIMIT %d',
                $this->table,
                $limit,
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'thread_id' => $threadId,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $messages = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (isset($row['metadata_json']) && is_string($row['metadata_json']) && $row['metadata_json'] !== '') {
                $decoded = json_decode($row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $messages[] = [
                'role' => (string) ($row['role'] ?? 'unknown'),
                'content' => (string) ($row['content'] ?? ''),
                'metadata' => $metadata,
            ];
        }

        return $messages;
    }

    private function archiveMessageSimple(string $threadId, string $role, string $content): void
    {
        $sql = sprintf(
            'INSERT INTO %s (tenant_id, user_id, thread_id, role, content, created_at, updated_at)
             VALUES (:tenant_id, :user_id, :thread_id, :role, :content, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'thread_id' => $threadId,
            'role' => $role,
            'content' => $content,
        ]);
    }
}
