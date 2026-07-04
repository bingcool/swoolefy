-- ChatHistory 热存储表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行后再使用 ChatHistoryFactory::sql() / ChatAgent
--
-- 用途：Neuron 原生 SQLChatHistory —— 每个 thread_id 一行，messages 为整段 JSON
-- 实现：NeuronAI\Chat\History\SQLChatHistory
--
-- 注意：使用前必须先创建本表，否则多轮持久化对话会失败

CREATE TABLE IF NOT EXISTS `chat_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `thread_id` VARCHAR(255) NOT NULL COMMENT '会话线程 ID（唯一）',
    `messages` LONGTEXT NOT NULL COMMENT 'Neuron 序列化的 messages JSON',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_chat_history_thread_id` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Neuron SQLChatHistory per-thread snapshot';
