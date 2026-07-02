<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use PDO;
use PDOException;

/**
 * ChatHistory SQL 冷归档 —— Redis 热存储之外的永久持久化。
 *
 * 表结构（需自行迁移）：
 *   CREATE TABLE chat_messages (
 *     id BIGINT AUTO_INCREMENT PRIMARY KEY,
 *     thread_id VARCHAR(128) NOT NULL,
 *     role VARCHAR(32) NOT NULL,
 *     content MEDIUMTEXT NOT NULL,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     INDEX idx_thread (thread_id)
 *   );
 */
final class SqlChatHistoryArchive
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'chat_messages',
    ) {
    }

    /**
     * 归档单条消息。
     *
     * @param array<string, mixed> $metadata
     */
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

    /** @param list<array{role: string, content: string, metadata?: array<string, mixed>}> $messages */
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
