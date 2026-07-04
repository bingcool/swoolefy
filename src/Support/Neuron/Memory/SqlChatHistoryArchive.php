<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use PDO;
use PDOException;

/**
 * ChatHistory SQL 冷归档 —— 永久持久化多轮会话。
 *
 * 表结构（需自行迁移）：
 *   CREATE TABLE chat_messages (
 *     id BIGINT AUTO_INCREMENT PRIMARY KEY,
 *     thread_id VARCHAR(128) NOT NULL,
 *     role VARCHAR(32) NOT NULL,
 *     content MEDIUMTEXT NOT NULL,
 *     metadata_json JSON NULL,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     INDEX idx_thread (thread_id)
 *   );
 */
final class SqlChatHistoryArchive implements ChatHistoryArchiveInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chat_messages',
    ) {
    }

    /** {@inheritdoc} */
    public function archiveMessage(string $threadId, string $role, string $content, array $metadata = []): void
    {
        $sql = sprintf(
            'INSERT INTO %s (thread_id, role, content, metadata_json, created_at) VALUES (:thread_id, :role, :content, :metadata, NOW())',
            $this->table,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'thread_id' => $threadId,
                'role' => $role,
                'content' => $content,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        } catch (PDOException) {
            // 降级：尝试无 metadata_json 列的简化表结构
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
            'SELECT role, content, metadata_json FROM %s WHERE thread_id = :thread_id ORDER BY id ASC LIMIT %d',
            $this->table,
            $limit,
        );

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['thread_id' => $threadId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {
            // 降级：无 metadata_json 列
            $sql = sprintf(
                'SELECT role, content FROM %s WHERE thread_id = :thread_id ORDER BY id ASC LIMIT %d',
                $this->table,
                $limit,
            );
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['thread_id' => $threadId]);
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
            'INSERT INTO %s (thread_id, role, content, created_at) VALUES (:thread_id, :role, :content, NOW())',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'thread_id' => $threadId,
            'role' => $role,
            'content' => $content,
        ]);
    }
}
