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

namespace Swoolefy\Support\Workflow;

/**
 * workflow.php → workflow.run_stores 驱动 / 别名常量。
 *
 * 配置与代码中请使用本类常量，避免魔法字符串。
 */
final class WorkflowRunStoreName
{
    /** 进程内内存（单测 / 单 Worker 演示）。 */
    public const MEMORY = 'memory';

    /** Redis 快照（跨 Worker，低延迟）。 */
    public const REDIS = 'redis';

    /** 关系库快照（跨 Worker，可查询、可审计）。 */
    public const DB = 'db';

    /**
     * 全部内置驱动名。
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MEMORY,
            self::REDIS,
            self::DB,
        ];
    }

    public static function isSupported(string $driver): bool
    {
        return in_array($driver, self::all(), true);
    }
}
