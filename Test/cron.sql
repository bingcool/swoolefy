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


CREATE TABLE `cron_agent_node_group` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `group_name` varchar(128) NOT NULL DEFAULT '' COMMENT '分组名称（唯一）',
    `remark` varchar(256) NOT NULL DEFAULT '' COMMENT '备注',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_group_name` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cron Agent 节点分组';

-- 已有库升级（不要 DROP 表）。节点分组 API：GET/POST/PUT/DELETE /api/v1/node-groups。
-- 新库按上方 CREATE TABLE 已含，无需再执行。若表已存在，跳过本段即可。
-- CREATE TABLE `cron_agent_node_group` (
--     `id` bigint unsigned NOT NULL AUTO_INCREMENT,
--     `group_name` varchar(128) NOT NULL DEFAULT '' COMMENT '分组名称（唯一）',
--     `remark` varchar(256) NOT NULL DEFAULT '' COMMENT '备注',
--     `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
--     `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
--     PRIMARY KEY (`id`),
--     UNIQUE KEY `uniq_group_name` (`group_name`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cron Agent 节点分组';

CREATE TABLE `cron_agent_node` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `group_id` int unsigned DEFAULT NULL COMMENT '所属分组ID（cron_agent_node_group.id）；历史数据可空',
    `node_name` varchar(128) NOT NULL DEFAULT '' COMMENT '节点名称',
    `node_ip` varchar(64) NOT NULL DEFAULT '' COMMENT '节点IP',
    `remark` varchar(256) NOT NULL DEFAULT '' COMMENT '备注',
    `last_heartbeat_at` datetime DEFAULT NULL COMMENT '最近一次 Agent 心跳时间',
    `heartbeat_interval` int unsigned NOT NULL DEFAULT '15' COMMENT '该节点心跳间隔（秒）；Ack 时由 Worker 写入，Admin 按节点自身间隔判定存活',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_group_id` (`group_id`),
    KEY `idx_last_heartbeat` (`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cron Agent 节点';

-- 已有库升级（不要 DROP 表）。Admin 存活判定用 heartbeat_interval；旧表无该列会回退默认 15，
-- 但 SELECT 该列时会报 Unknown column。新库按上方 CREATE TABLE 已含，无需再执行。
-- 若列已存在，跳过本段即可。
-- ALTER TABLE `cron_agent_node`
--     ADD COLUMN `heartbeat_interval` int unsigned NOT NULL DEFAULT '15' COMMENT '该节点心跳间隔（秒）；Ack 时由 Worker 写入，Admin 按节点自身间隔判定存活' AFTER `last_heartbeat_at`;

-- 节点软删除（DELETE /api/v1/nodes 写 deleted_at，不物理删行）。新库 CREATE 已含 deleted_at。
-- ALTER TABLE `cron_agent_node`
--     ADD COLUMN `deleted_at` datetime DEFAULT NULL COMMENT '删除时间' AFTER `updated_at`;

-- 节点所属分组。新库 CREATE 已含 group_id。历史节点允许为空（列表显示「未分组」）；新建/更新节点 API 必填。
-- 若列已存在，跳过本段即可。
-- ALTER TABLE `cron_agent_node`
--     ADD COLUMN `group_id` int unsigned DEFAULT NULL COMMENT '所属分组ID（cron_agent_node_group.id）；历史数据可空' AFTER `id`,
--     ADD KEY `idx_group_id` (`group_id`);

CREATE TABLE `cron_task_run_request` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `cron_id` bigint NOT NULL DEFAULT '0' COMMENT '关联 cron_task.id',
    `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入队时间',
    `consumed_at` datetime DEFAULT NULL COMMENT 'Cron Worker 消费时间；NULL=待执行',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_cron_pending` (`cron_id`, `consumed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动执行请求（跨进程 runOnceNow 入队）';

CREATE TABLE `cron_task_log` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `cron_id` bigint NOT NULL DEFAULT '0' COMMENT '关联的cron_task.id',
    `exec_batch_id` varchar(64) NOT NULL DEFAULT '' COMMENT '每轮执行的批次id',
    `pid` int NOT NULL DEFAULT '0' COMMENT '定时脚本执行时的进程pid',
    `status` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '执行状态：0-pending 1-running 2-success 3-failed 4-skipped 5-timeout 6-cancelled',
    `trigger_type` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '触发类型：1-scheduler 2-run_once',
    `scheduled_at` datetime DEFAULT NULL COMMENT '计划执行时间',
    `started_at` datetime DEFAULT NULL COMMENT '实际开始执行时间',
    `finished_at` datetime DEFAULT NULL COMMENT '实际结束执行时间',
    `duration_ms` bigint unsigned NOT NULL DEFAULT '0' COMMENT '执行耗时，毫秒',
    `exit_code` int DEFAULT NULL COMMENT 'Shell退出码',
    `http_status` smallint unsigned DEFAULT NULL COMMENT 'HTTP响应状态码',
    `task_item` text DEFAULT NULL COMMENT '执行任务项meta信息',
    `message` text DEFAULT NULL COMMENT '运行态记录信息（人类可读，禁止用于 taskStats）',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    KEY `expression` (`exec_batch_id`),
    KEY `idx_cron_id_created_at` (`cron_id`, `created_at`),
    KEY `idx_cron_status_created_at` (`cron_id`, `status`, `created_at`),
    KEY `idx_status_created_at` (`status`, `created_at`),
    KEY `idx_cron_exec_batch` (`cron_id`, `exec_batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务执行记录（Execution Record）';

-- 已有库升级（不要 DROP 表）。taskStats 改为 GROUP BY status，旧表无下列列会报 Unknown column。
-- 新库按上方 CREATE TABLE 已含，无需再执行。若列/索引已存在，跳过对应语句即可。
-- ALTER TABLE `cron_task_log`
--     ADD COLUMN `status` tinyint unsigned NOT NULL DEFAULT 0
--         COMMENT '执行状态：0-pending 1-running 2-success 3-failed 4-skipped 5-timeout 6-cancelled'
--         AFTER `pid`,
--     ADD COLUMN `trigger_type` tinyint unsigned NOT NULL DEFAULT 1
--         COMMENT '触发类型：1-scheduler 2-run_once'
--         AFTER `status`,
--     ADD COLUMN `scheduled_at` datetime DEFAULT NULL
--         COMMENT '计划执行时间'
--         AFTER `trigger_type`,
--     ADD COLUMN `started_at` datetime DEFAULT NULL
--         COMMENT '实际开始执行时间'
--         AFTER `scheduled_at`,
--     ADD COLUMN `finished_at` datetime DEFAULT NULL
--         COMMENT '实际结束执行时间'
--         AFTER `started_at`,
--     ADD COLUMN `duration_ms` bigint unsigned NOT NULL DEFAULT 0
--         COMMENT '执行耗时，毫秒'
--         AFTER `finished_at`,
--     ADD COLUMN `exit_code` int DEFAULT NULL
--         COMMENT 'Shell退出码'
--         AFTER `duration_ms`,
--     ADD COLUMN `http_status` smallint unsigned DEFAULT NULL
--         COMMENT 'HTTP响应状态码'
--         AFTER `exit_code`;
-- ALTER TABLE `cron_task_log` ADD KEY `idx_cron_id_created_at` (`cron_id`, `created_at`);
-- ALTER TABLE `cron_task_log` ADD KEY `idx_cron_status_created_at` (`cron_id`, `status`, `created_at`);
-- ALTER TABLE `cron_task_log` ADD KEY `idx_status_created_at` (`status`, `created_at`);
-- ALTER TABLE `cron_task_log` ADD KEY `idx_cron_exec_batch` (`cron_id`, `exec_batch_id`);
--
-- 历史数据一次性迁移：只改能确认的旧文案，无法识别的保持 status=0（不要伪装成真实 PENDING）。
-- UPDATE `cron_task_log` SET `status` = 4 WHERE `status` = 0 AND (`message` LIKE '%】SKIP %' OR `message` LIKE '%】SKIPPED %' OR `message` = 'skipped');
-- UPDATE `cron_task_log` SET `status` = 5 WHERE `status` = 0 AND `message` LIKE '%】TIMEOUT %';
-- UPDATE `cron_task_log` SET `status` = 6 WHERE `status` = 0 AND `message` LIKE '%】CANCELLED %';
-- UPDATE `cron_task_log` SET `status` = 3 WHERE `status` = 0 AND (`message` LIKE '%】FAILED %' OR `message` LIKE '%执行异常已隔离%' OR `message` = 'failed');
-- UPDATE `cron_task_log` SET `status` = 2 WHERE `status` = 0 AND (`message` LIKE '%】SUCCESS %' OR `message` = 'success');
