-- Workflow Run 持久化表（MySQL / MariaDB / InnoDB）
-- 生产环境请手工执行；workflow.php 中 db.auto_migrate=false（默认）
--
-- 用途：跨 Worker 保存 WorkflowRun 快照，支持 status / resume / HITL listWaiting

CREATE TABLE IF NOT EXISTS `workflow_runs` (
    `run_id` VARCHAR(64) NOT NULL COMMENT 'Run 实例 ID',
    `workflow_id` VARCHAR(128) NOT NULL COMMENT '工作流定义 ID',
    `version` VARCHAR(32) NOT NULL DEFAULT '1.0.0' COMMENT '定义版本',
    `status` VARCHAR(32) NOT NULL COMMENT 'RunStatus 枚举值',
    `pause_node_id` VARCHAR(128) NULL COMMENT 'WAITING 时暂停节点',
    `assignee` VARCHAR(128) NULL COMMENT 'HITL 处理人（自 pause 节点 output 提取）',
    `payload` LONGTEXT NOT NULL COMMENT 'WorkflowRunSnapshot JSON',
    `created_at` INT UNSIGNED NOT NULL COMMENT '创建时间戳',
    `updated_at` INT UNSIGNED NOT NULL COMMENT '更新时间戳',
    PRIMARY KEY (`run_id`),
    KEY `idx_workflow_runs_status_updated` (`status`, `updated_at`),
    KEY `idx_workflow_runs_status_assignee` (`status`, `assignee`),
    KEY `idx_workflow_runs_workflow_id` (`workflow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workflow engine run snapshots';
