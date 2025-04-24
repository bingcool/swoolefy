CREATE TABLE `cron_task` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(128) NOT NULL DEFAULT '' COMMENT '任务名称',
    `expression` varchar(128) NOT NULL DEFAULT '' COMMENT 'cron表达式',
    `command` varchar(256) NOT NULL DEFAULT '' COMMENT '执行命令',
    `exec_type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '执行类型 1-shell，2-http',
    `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态 0-禁用，1-启用',
    `with_block_lapping` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否阻塞执行 0-否，1->是',
    `description` varchar(256) NOT NULL DEFAULT '' COMMENT '描述',
    `cron_between` json DEFAULT NULL COMMENT '允许执行时间段',
    `cron_skip` json DEFAULT NULL COMMENT '不允许执行时间段(即需跳过的时间段)',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
    `deleted_at` datetime DEFAULT NULL COMMENT '删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name` (`name`),
    KEY `expression` (`expression`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务表';