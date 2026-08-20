# Swoolefy Cron `taskStats()` 优化技术方案

## 1. 目标

移除 `taskStats()` 对 `cron_task_log.message` 文本判断 `success / failed / skipped` 的依赖，改为基于结构化的 `status` 字段统计。

原则：

- 不新增 Redis / MQ / 独立统计服务
- 不改变 DB Polling、ConfigDiff、RuntimeJobRegistry、Scheduler
- 不重做 Cron 架构
- 只优化 Execution 数据模型、日志写入和 `taskStats()`

## 2. 核心问题

旧模式：

```text
cron_task_log
    ↓
message
    ↓
taskStats() 解析字符串
```

存在：

1. 文案变化会导致统计错误
2. 无法可靠区分 failed / timeout / cancelled
3. 无法有效使用数据库索引
4. Dashboard 与日志文本耦合
5. 后续执行记录查询越来越复杂

新模式：

```text
Executor
    ↓
Execution Status
    ↓
cron_task_log.status
    ↓
SQL GROUP BY
    ↓
taskStats()
```

## 3. 推荐状态

```text
0 PENDING
1 RUNNING
2 SUCCESS
3 FAILED
4 SKIPPED
5 TIMEOUT
6 CANCELLED
```

代码中建议统一定义常量：

```php
private const STATUS_PENDING = 0;
private const STATUS_RUNNING = 1;
private const STATUS_SUCCESS = 2;
private const STATUS_FAILED = 3;
private const STATUS_SKIPPED = 4;
private const STATUS_TIMEOUT = 5;
private const STATUS_CANCELLED = 6;
```

如当前项目已经存在统一状态常量，应优先复用。

## 4. `cron_task_log` 推荐增加字段

```sql
ALTER TABLE `cron_task_log`
    ADD COLUMN `status` tinyint unsigned NOT NULL DEFAULT 0
        COMMENT '执行状态：0-pending 1-running 2-success 3-failed 4-skipped 5-timeout 6-cancelled'
        AFTER `pid`,
    ADD COLUMN `trigger_type` tinyint unsigned NOT NULL DEFAULT 1
        COMMENT '触发类型：1-scheduler 2-run_once'
        AFTER `status`,
    ADD COLUMN `scheduled_at` datetime DEFAULT NULL
        COMMENT '计划执行时间'
        AFTER `trigger_type`,
    ADD COLUMN `started_at` datetime DEFAULT NULL
        COMMENT '实际开始执行时间'
        AFTER `scheduled_at`,
    ADD COLUMN `finished_at` datetime DEFAULT NULL
        COMMENT '实际结束执行时间'
        AFTER `started_at`,
    ADD COLUMN `duration_ms` bigint unsigned NOT NULL DEFAULT 0
        COMMENT '执行耗时，毫秒'
        AFTER `finished_at`,
    ADD COLUMN `exit_code` int DEFAULT NULL
        COMMENT 'Shell退出码'
        AFTER `duration_ms`,
    ADD COLUMN `http_status` smallint unsigned DEFAULT NULL
        COMMENT 'HTTP响应状态码'
        AFTER `exit_code`;
```

推荐索引：

```sql
KEY `idx_cron_id_created_at` (`cron_id`, `created_at`),
KEY `idx_cron_status_created_at` (`cron_id`, `status`, `created_at`),
KEY `idx_status_created_at` (`status`, `created_at`)
```

已有同类索引时不要重复创建。

## 5. `message` 的职责

不要删除 `message`。

应明确：

```text
status
    = 机器可判断的结构化状态

message
    = 人类可阅读的运行信息
```

例如：

```text
status = FAILED
message = Shell command exit code: 1
```

或：

```text
status = SKIPPED
message = Previous execution is still running
```

`taskStats()` 永远不要再通过 `message` 推断状态。

## 6. Execution 状态流转

```text
PENDING
   │
   ▼
RUNNING
   │
   ├── SUCCESS
   ├── FAILED
   ├── TIMEOUT
   └── CANCELLED

PENDING
   │
   └── ExecutionGuard 阻塞
            ↓
         SKIPPED
```

正常 Scheduler：

```text
trigger_type = 1
```

RunOnce：

```text
trigger_type = 2
```

## 7. taskStats() 查询原则

禁止：

```text
SELECT *
FROM cron_task_log
    ↓
PHP foreach
    ↓
解析 message
```

应该：

```sql
SELECT status, COUNT(*) AS total
FROM cron_task_log
WHERE cron_id = ?
GROUP BY status;
```

时间范围：

```sql
SELECT status, COUNT(*) AS total
FROM cron_task_log
WHERE cron_id = ?
  AND created_at >= ?
  AND created_at < ?
GROUP BY status;
```

这样统计工作由 MySQL 完成，不需要将所有日志加载到 PHP。

## 8. 推荐返回结构

```php
[
    'total' => 1000,
    'pending' => 0,
    'running' => 0,
    'success' => 950,
    'failed' => 20,
    'skipped' => 30,
    'timeout' => 0,
    'cancelled' => 0,
]
```

空数据也必须返回完整结构，所有计数为 `0`。

## 9. `taskStats()` 推荐实现

实际 ORM API 应以当前 Swoolefy 项目为准，核心逻辑：

```php
public function taskStats(
    int $cronId,
    ?string $start = null,
    ?string $end = null
): array {
    $query = CronTaskLog::query()
        ->where('cron_id', $cronId);

    if ($start !== null) {
        $query->where('created_at', '>=', $start);
    }

    if ($end !== null) {
        $query->where('created_at', '<', $end);
    }

    $rows = $query
        ->selectRaw('status, COUNT(*) AS total')
        ->groupBy('status')
        ->get();

    $stats = [
        'total' => 0,
        'pending' => 0,
        'running' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'timeout' => 0,
        'cancelled' => 0,
    ];

    foreach ($rows as $row) {
        $count = (int) $row->total;

        switch ((int) $row->status) {
            case self::STATUS_PENDING:
                $stats['pending'] = $count;
                break;
            case self::STATUS_RUNNING:
                $stats['running'] = $count;
                break;
            case self::STATUS_SUCCESS:
                $stats['success'] = $count;
                break;
            case self::STATUS_FAILED:
                $stats['failed'] = $count;
                break;
            case self::STATUS_SKIPPED:
                $stats['skipped'] = $count;
                break;
            case self::STATUS_TIMEOUT:
                $stats['timeout'] = $count;
                break;
            case self::STATUS_CANCELLED:
                $stats['cancelled'] = $count;
                break;
        }

        $stats['total'] += $count;
    }

    return $stats;
}
```

如果当前项目使用的 ORM API 不支持上述写法，应保持相同 SQL 语义而使用现有查询接口。

## 10. 执行记录写入时机

### 创建 Execution

```text
status = PENDING
scheduled_at = 计划时间
trigger_type = SCHEDULER / RUN_ONCE
```

### 开始执行

```text
status = RUNNING
started_at = now()
pid = 当前进程 PID
```

### Shell 成功

```text
status = SUCCESS
finished_at = now()
duration_ms = ...
exit_code = 0
```

### Shell 失败

```text
status = FAILED
finished_at = now()
duration_ms = ...
exit_code = 非 0
message = 错误信息
```

### HTTP 成功

```text
status = SUCCESS
http_status = 2xx
```

### HTTP 失败

```text
status = FAILED
http_status = 4xx / 5xx
```

### Timeout

```text
status = TIMEOUT
```

### 防重叠

```text
status = SKIPPED
message = previous execution is still running
```

重要原则：

> 每一次计划调度都应该能够在 `cron_task_log` 中找到对应的 Execution 结果，包括 SKIPPED。

## 11. Execution 与 taskStats() 解耦

建议职责：

```text
Execution
    ↓
产生 status / duration / exit_code / http_status
    ↓
cron_task_log
```

而：

```text
taskStats()
    ↓
只读取结构化字段
```

`taskStats()` 不应该理解 Shell / HTTP 的执行细节。

## 12. Dashboard 查询

任务维度：

```sql
SELECT status, COUNT(*) AS total
FROM cron_task_log
WHERE cron_id = ?
  AND created_at >= ?
  AND created_at < ?
GROUP BY status;
```

全局 Dashboard：

```sql
SELECT status, COUNT(*) AS total
FROM cron_task_log
WHERE created_at >= ?
GROUP BY status;
```

任务 + 状态：

```sql
SELECT cron_id, status, COUNT(*) AS total
FROM cron_task_log
WHERE created_at >= ?
GROUP BY cron_id, status;
```

## 13. 执行耗时统计

增加 `duration_ms` 后可以直接：

```sql
SELECT AVG(duration_ms)
FROM cron_task_log
WHERE cron_id = ?
AND status = 2;
```

以及：

```sql
SELECT MAX(duration_ms)
FROM cron_task_log
WHERE cron_id = ?
AND status = 2;
```

不要从 `message` 中解析耗时。

## 14. 成功率

建议同时展示：

```text
执行次数
成功次数
失败次数
跳过次数
超时次数
取消次数
成功率
```

成功率的分母应明确业务语义。

推荐将：

```text
finished =
SUCCESS + FAILED + SKIPPED + TIMEOUT + CANCELLED
```

但 `SKIPPED` 是否进入成功率分母，应由产品定义，不能隐式处理。

## 15. 统计与执行列表分离

```text
taskStats()
    ↓
COUNT / GROUP BY

taskLogs()
    ↓
分页执行记录

taskLogDetail()
    ↓
message / task_item / 执行详情
```

`taskStats()` 不查询 `TEXT` 类型的 `message`、`task_item`。

## 16. 历史数据迁移

增加 `status` 后，旧记录不能直接默认认为 PENDING。

建议：

1. 增加字段。
2. 根据当前项目真实 `message` 格式进行一次性迁移。
3. 新代码全部写入结构化 `status`。
4. 验证迁移结果。
5. 新统计完全基于 `status`。

例如，只有在确认旧数据格式确实如此时，才能执行：

```sql
UPDATE cron_task_log
SET status = 2
WHERE message = 'success'
AND status = 0;
```

不能把无法识别的历史记录伪装成 PENDING。

## 17. 测试方案

### 状态统计

插入：

```text
SUCCESS  10
FAILED    2
SKIPPED   3
TIMEOUT   1
```

应返回：

```text
total = 16
success = 10
failed = 2
skipped = 3
timeout = 1
```

### message 不影响统计

```text
status = SUCCESS
message = "执行失败？"
```

仍然必须统计为 SUCCESS。

### 时间范围

验证：

```text
created_at >= start
created_at < end
```

避免边界重复。

### 空数据

必须返回完整零值结构。

### 大数据量

使用大量执行记录通过 `EXPLAIN` 验证：

```text
idx_cron_status_created_at
```

并确认 PHP 内存不会随日志量线性增长。

## 18. 修改范围

建议控制在：

```text
CronTaskLog Model
    ├── status
    ├── trigger_type
    ├── scheduled_at
    ├── started_at
    ├── finished_at
    ├── duration_ms
    ├── exit_code
    └── http_status

Cron Execution
    └── 正确写入 Execution Status

CronTaskManagerService
    └── taskStats()

Cron Dashboard
    └── 使用结构化统计

cron.sql
    └── migration + indexes
```

不要因为本次优化去修改：

```text
DB Polling
ConfigDiff
RuntimeJobRegistry
Scheduler
Timer
```

除非现有 Execution 写日志位置必须补充 status。

## 19. 最终架构

```text
cron_task
    │
    ▼
Scheduler
    │
    ▼
Execution
    │
    ├── status
    ├── duration_ms
    ├── exit_code / http_status
    └── message
    │
    ▼
cron_task_log
    │
    ├── taskStats()
    │       └── SQL GROUP BY status
    │
    ├── Dashboard
    │
    └── Execution Detail
```

最终职责：

```text
status
    = 结构化执行状态

duration_ms
    = 执行性能数据

exit_code / http_status
    = Executor 结果

message
    = 人类可读运行信息

task_item
    = Execution 元数据
```

## 20. 最终结论

本次优化真正要解决的不是简单的：

```text
message → status
```

而是正式建立：

> **Cron Execution Record 数据模型。**

旧模式：

```text
cron_task_log
    ↓
message
    ↓
taskStats() 猜状态
```

新模式：

```text
Cron Executor
    ↓
Execution Status
    ↓
cron_task_log.status
    ↓
taskStats()
    ↓
SQL GROUP BY
```

这样 Web Admin 的执行次数、成功、失败、跳过、超时、耗时和 Dashboard 统计都建立在可靠的结构化事实数据上，同时不改变当前 Swoolefy Cron 的 DB Polling + Runtime + Scheduler 核心架构。

**建议本次只完成 Execution Status 数据模型、执行结果写入和 `taskStats()`，不要顺便引入 Retry、告警或独立 Metrics 系统。**
