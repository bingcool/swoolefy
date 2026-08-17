CREATE TABLE `cron_task` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `node_id` int unsigned NOT NULL DEFAULT '1' COMMENT '节点ID',
    `cron_name` varchar(128) NOT NULL DEFAULT '' COMMENT '任务名称（API 字段 name）',
    `expression` varchar(128) NOT NULL DEFAULT '' COMMENT 'cron表达式',
    `command` varchar(256) NOT NULL DEFAULT '' COMMENT '执行命令',
    `exec_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '执行类型 1-shell，2-http',
    `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态 0-禁用，1-启用',
    `with_block_lapping` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否阻塞执行 0-否，1->是',
    `retry` int NOT NULL DEFAULT '0' COMMENT '失败后重试次数（不含首次；0=不重试，N=最多再试N次）',
    `description` varchar(256) NOT NULL DEFAULT '' COMMENT '描述',
    `cron_between` json DEFAULT NULL COMMENT '允许执行时间段',
    `cron_skip` json DEFAULT NULL COMMENT '不允许执行时间段(即需跳过的时间段)',
    `http_method` varchar(16) NOT NULL DEFAULT '' COMMENT 'http请求方法',
    `http_body` json DEFAULT NULL COMMENT 'http请求体',
    `http_headers` json DEFAULT NULL COMMENT 'http请求头',
    `http_request_time_out` int NOT NULL DEFAULT '0' COMMENT 'http请求超时时间，单位：秒',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cron_name` (`cron_name`),
    KEY `node_id` (`node_id`),
    KEY `expression` (`expression`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务表';

-- 已有库升级（不要 DROP 表，避免丢数据）。
-- 列表 API GET /api/v1/tasks 会 SELECT retry；旧表无该列会报 Unknown column 'retry'。
-- 新库按上方 CREATE TABLE 已含 retry（默认 0=不重试），无需再执行。
-- 若列已存在，跳过本段即可。
-- ALTER TABLE `cron_task`
--     ADD COLUMN `retry` int NOT NULL DEFAULT '0' COMMENT '失败后重试次数（不含首次；0=不重试，N=最多再试N次）' AFTER `with_block_lapping`;

// 插入数据
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (1, 'shell-1', '15', '/bin/bash /home/wwwroot/swoolefy/Test/Python/shell.sh --type=1', 1, 0, 0, '', NULL, NULL, '', NULL, NULL, 0, '2025-04-24 19:12:34', '2025-04-27 19:10:20', NULL);
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (2, 'shell-2', '15', '/bin/bash /home/wwwroot/swoolefy/Test/Python/shell.sh --type=2', 1, 0, 0, '', NULL, NULL, '', NULL, NULL, 0, '2025-04-24 19:13:10', '2025-04-25 18:29:48', NULL);
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (3, 'shell-3266', '15', '/bin/bash /home/wwwroot/swoolefy/Test/Python/shell.sh --type=3', 1, 0, 0, '12345', NULL, NULL, '', NULL, NULL, 0, '2025-04-24 19:13:32', '2025-04-25 18:29:48', NULL);
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (4, 'shell-4567', '20', '/bin/bash /home/wwwroot/swoolefy/Test/Python/shell.sh --type=4', 1, 0, 0, '', NULL, NULL, '', NULL, NULL, 0, '2025-04-25 11:42:51', '2025-04-25 18:29:49', NULL);
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (5, 'http-1', '20', 'http://127.0.0.1:9501/index/index', 2, 1, 0, '334', NULL, NULL, 'GET', NULL, NULL, 0, '2025-04-25 16:18:10', '2025-04-27 19:55:22', NULL);
INSERT INTO `cron_task` (`id`, `cron_name`, `expression`, `command`, `exec_type`, `status`, `with_block_lapping`, `description`, `cron_between`, `cron_skip`, `http_method`, `http_body`, `http_headers`, `http_request_time_out`, `created_at`, `updated_at`, `deleted_at`) VALUES (6, '修复用户数据', '25', 'php script.php start Test --c=test:script', 1, 1, 0, '', NULL, NULL, '', NULL, NULL, 0, '2025-04-27 09:51:55', '2025-04-27 19:53:01', NULL);


CREATE TABLE `cron_agent_node` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `node_name` varchar(128) NOT NULL DEFAULT '' COMMENT '节点名称',
    `node_ip` varchar(64) NOT NULL DEFAULT '' COMMENT '节点IP',
    `remark` varchar(256) NOT NULL DEFAULT '' COMMENT '备注',
    `last_heartbeat_at` datetime DEFAULT NULL COMMENT '最近一次 Agent 心跳时间',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    PRIMARY KEY (`id`),
    KEY `idx_last_heartbeat` (`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cron Agent 节点';

CREATE TABLE `cron_task_run_request` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `cron_id` bigint NOT NULL DEFAULT '0' COMMENT '关联 cron_task.id',
    `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入队时间',
    `consumed_at` datetime DEFAULT NULL COMMENT 'Cron Worker 消费时间；NULL=待执行',
    PRIMARY KEY (`id`),
    KEY `idx_cron_pending` (`cron_id`, `consumed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动执行请求（跨进程 runOnceNow 入队）';

CREATE TABLE `cron_task_log` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `cron_id` bigint NOT NULL DEFAULT '0' COMMENT '关联的cron_task.id',
    `exec_batch_id` varchar(64) NOT NULL DEFAULT '' COMMENT '每轮执行的批次id',
    `pid` int NOT NULL DEFAULT '0' COMMENT '定时脚本执行时的进程pid',
    `task_item` text DEFAULT NULL COMMENT '执行任务项meta信息',
    `message` text DEFAULT NULL COMMENT '运行态记录信息',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `expression` (`exec_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务表运行态日志';
