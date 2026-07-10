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
 * 单测专用：在 SQLite 内存库安装 workflow_runs 表（生产须预执行 Schema/workflow_runs.sql）。
 */
final class WorkflowRunsSchemaInstaller
{
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
