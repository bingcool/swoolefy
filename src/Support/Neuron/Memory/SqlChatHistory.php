<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron\Memory;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;

/**
 * SQL 热存储 ChatHistory —— 按 tenant_id + thread_id 隔离，支持软删。
 *
 * 使用前须执行 {@see Schema/chat_history.sql}。
 *
 * @see ChatHistoryFactory::sql()
 */
final class SqlChatHistory extends AbstractChatHistory implements HotChatHistoryInterface
{
    private readonly string $table;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $userId,
        private readonly string $threadId,
        private readonly PDO $pdo,
        string $table = 'chat_history',
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);
        $this->table = $this->sanitizeTableName($table);
        $this->load();
    }

    public function threadId(): string
    {
        return $this->threadId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    protected function setMessages(array $messages): void
    {
        $sql = sprintf(
            'UPDATE %s SET messages = :messages, user_id = :user_id, updated_at = CURRENT_TIMESTAMP, deleted_at = NULL
             WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NULL',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'messages' => json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR),
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'thread_id' => $this->threadId,
        ]);
    }

    protected function clear(): void
    {
        $sql = sprintf(
            'UPDATE %s SET messages = :messages, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NULL',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'messages' => '[]',
            'tenant_id' => $this->tenantId,
            'thread_id' => $this->threadId,
        ]);
    }

    private function load(): void
    {
        $row = $this->fetchActiveRow();
        if ($row === null) {
            $this->reviveSoftDeletedRow();
            $row = $this->fetchActiveRow();
        }

        if ($row === null) {
            $this->insertEmptyRow();
            return;
        }

        $decoded = json_decode((string) ($row['messages'] ?? '[]'), true);
        $this->history = $this->deserializeMessages(is_array($decoded) ? $decoded : []);
    }

    /** @return array<string, mixed>|null */
    private function fetchActiveRow(): ?array
    {
        $sql = sprintf(
            'SELECT messages FROM %s WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NULL LIMIT 1',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'thread_id' => $this->threadId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function reviveSoftDeletedRow(): void
    {
        $sql = sprintf(
            'UPDATE %s SET deleted_at = NULL, messages = :messages, user_id = :user_id, updated_at = CURRENT_TIMESTAMP
             WHERE tenant_id = :tenant_id AND thread_id = :thread_id AND deleted_at IS NOT NULL',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'messages' => '[]',
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'thread_id' => $this->threadId,
        ]);
    }

    private function insertEmptyRow(): void
    {
        $sql = sprintf(
            'INSERT INTO %s (tenant_id, user_id, thread_id, messages) VALUES (:tenant_id, :user_id, :thread_id, :messages)',
            $this->table,
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'thread_id' => $this->threadId,
            'messages' => '[]',
        ]);
    }

    private function sanitizeTableName(string $tableName): string
    {
        $tableName = trim($tableName);
        if (!$this->tableExists($tableName)) {
            throw new ChatHistoryException('Table not allowed');
        }
        if (preg_match('/^[a-zA-Z_]\w*$/', $tableName) !== 1) {
            throw new ChatHistoryException('Invalid table name format');
        }

        return $tableName;
    }

    private function tableExists(string $tableName): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $query = match ($driver) {
            'mysql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name',
            'pgsql' => 'SELECT 1 FROM information_schema.tables WHERE table_catalog = current_database() AND table_name = :table_name',
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name",
            default => throw new ChatHistoryException("Unsupported database driver: {$driver}"),
        };

        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['table_name' => $tableName]);

        return $stmt->fetch() !== false;
    }
}
