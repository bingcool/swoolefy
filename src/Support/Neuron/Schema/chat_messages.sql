-- ChatHistory 冷归档表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行后再启用 SqlChatHistoryArchive
--
-- 用途：按消息逐条持久化多轮会话（thread_id + role + content）
-- 实现：Swoolefy\Support\Neuron\Memory\SqlChatHistoryArchive
--
-- 注意：
--   1. 使用前必须先创建本表，否则 archiveMessage / listMessages 会失败
--   2. 与 Neuron 原生 SQLChatHistory（表 chat_history，整段 messages JSON）不同
--      见 Schema/chat_history.sql
--   3. metadata_json 为可选扩展列；若数据库不支持 JSON，可改为 TEXT

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
    `thread_id` VARCHAR(128) NOT NULL COMMENT '会话线程 ID',
    `role` VARCHAR(32) NOT NULL COMMENT '消息角色：user / assistant / system 等',
    `content` MEDIUMTEXT NOT NULL COMMENT '消息正文',
    `metadata_json` JSON NULL COMMENT '可选元数据（工具调用、token 等）',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '写入时间',
    PRIMARY KEY (`id`),
    KEY `idx_chat_messages_thread_id` (`thread_id`),
    KEY `idx_chat_messages_thread_created` (`thread_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Agent chat history cold archive (per message row)';
