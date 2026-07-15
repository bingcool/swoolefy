<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests;

use PDO;

/**
 * 单测专用：在 SQLite 内存库安装 `workflow_runs` 表结构。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | install | 创建 workflow_runs 主表及 status/assignee 索引 |
 * | 约束 | 仅支持 SQLite；表名须符合标识符规则 |
 *
 * 生产环境须预执行 `Schema/workflow_runs.sql`，本类仅供单元/集成测试快速建表。
 */
final class WorkflowRunsSchemaInstaller
{
    /**
     * 在给定 PDO（须为 SQLite）上创建 workflow_runs 表及查询索引。
     *
     * 验证目的：DbRunStore 相关用例无需依赖外部数据库迁移即可在内存库中运行。
     * 表结构对齐生产 schema：run_id、workflow_id、status、pause_node_id、assignee、payload 等。
     *
     * @param PDO $pdo SQLite 连接（通常为 `sqlite::memory:`）
     * @param string $table 表名，默认 `workflow_runs`
     * @throws \RuntimeException 非 SQLite 驱动时抛出
     * @throws \InvalidArgumentException 表名非法时抛出
     */
    public static function install(PDO $pdo, string $table = 'workflow_runs'): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \RuntimeException('WorkflowRunsSchemaInstaller only supports sqlite (unit tests).');
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table name [{$table}]");
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                run_id TEXT NOT NULL PRIMARY KEY,
                workflow_id TEXT NOT NULL,
                version TEXT NOT NULL DEFAULT '1.0.0',
                status TEXT NOT NULL,
                pause_node_id TEXT NULL,
                assignee TEXT NULL,
                payload TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at TEXT NULL
            )",
        );
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_status_updated ON {$table}(status, updated_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_status_assignee ON {$table}(status, assignee)");
    }
}
