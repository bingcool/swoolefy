-- support/claim 的建表语句。业务接入时把本文件复制到自己的 migrations 目录，
-- 按需改表名即可（代码里用 Config.Table 指向同一个表名）。
--
-- 要求 MySQL 8：认领语句用 FOR UPDATE SKIP LOCKED，多副本才能安全地扫同一张表。
-- 表由应用自己维护：框架既不迁移也不改结构，只会读写下列查询依赖的列。
--
-- 关键约定：
--   status 0待认领 1已认领(进行中) 2已完成 3处理失败(待人工介入)；
--   owner + attempts 是收尾围栏，Succeed/Fail 必须原样传回 Claim 返回的 Task；
--   lease_until 是租约（用数据库时钟比较），next_run_at 是到期时间，NULL 表示立即可认领；
--   idx_claim 不能省：认领按 (job_type, status, next_run_at, id) 排队，缺索引会退化成
--   全表扫描并持有大量行锁；
--   start_handle_time / finish_handle_time 分别记录最近一次进入 status 1/2 的墙钟时间；
--   fail_handle_time 记录最近一次失败时间（每次 Fail 都写，含退避重试回 pending）；
--   fail_reason 由 Fail 写、Succeed 清空；created_by / deleted_at 由应用维护，claim 不读写，
--   deleted_at 非空也不会让行从 Claim 里消失——要软删语义需自己给所有查询加过滤；
--   rerun_allowed 默认 1，业务插入时按行设置：0 表示这行只允许执行一次。claim 只读不写，
--   置 0 后 handler 报错直接转 status 3（不退避重试），租约过期也直接转 3（不回待认领）。
--
-- 时间列用普通 DATETIME（秒精度）：claim 在 Go 侧把租约与退避向上取整到整秒，
-- SQL 里的过期比较仍用 NOW(3)，两种精度都能正确比较。
CREATE TABLE IF NOT EXISTS `claim_job_task` (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  job_type    VARCHAR(64)  NOT NULL COMMENT '任务类型',
  status      TINYINT      NOT NULL DEFAULT 0 COMMENT '任务状态：0待认领(待处理) 1已认领(进行中) 2已完成 3处理失败,待人工介入',
  owner       VARCHAR(96)  NOT NULL DEFAULT '' COMMENT '当前认领者',
  lease_until DATETIME     NULL COMMENT '租约到期时间',
  attempts    INT          NOT NULL DEFAULT 0 COMMENT '已认领次数',
  next_run_at DATETIME     NULL COMMENT '下次可执行时间，NULL表示立即可认领',
  rerun_allowed TINYINT    NOT NULL DEFAULT 1 COMMENT '是否允许被再次消费：1允许(失败重试/租约过期后可被重跑)，0只执行一次(失败或租约过期直接转3)',
  payload     JSON         NULL COMMENT '任务载荷',
  fail_reason TEXT         NULL DEFAULT NULL COMMENT '失败原因',
  start_handle_time  DATETIME NULL DEFAULT NULL COMMENT '状态改为1(已认领进行中)的时间',
  finish_handle_time DATETIME NULL DEFAULT NULL COMMENT '状态改为2(已完成)的时间',
  fail_handle_time   DATETIME NULL DEFAULT NULL COMMENT '最近一次失败时间',
  created_by  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '创建人',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '创建时间',
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  deleted_at  DATETIME     NULL DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (id),
  KEY idx_claim (job_type, status, next_run_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='认领任务表';
