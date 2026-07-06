-- ChatHistory 热存储表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行后再使用 ChatHistoryFactory::sql() / ChatAgent
--
-- 用途：每个 (tenant_id, thread_id) 一行，messages 为整段 JSON
-- 实现：Swoolefy\Support\Neuron\Memory\SqlChatHistory
--
-- 注意：使用前必须先创建本表，否则多轮持久化对话会失败

CREATE TABLE IF NOT EXISTS `chat_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `tenant_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '租户ID',
    `user_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '用户ID，便于按用户查会话',
    `thread_id` VARCHAR(128) NOT NULL COMMENT '会话线程 ID',
    `messages` LONGTEXT NOT NULL COMMENT 'Neuron 序列化的 messages JSON',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` DATETIME DEFAULT NULL COMMENT '删除时间（软删）',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_tenant_thread` (`tenant_id`, `thread_id`),
    KEY `idx_tenant_user` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Agent SQL chat history hot storage (per tenant+thread snapshot)';
