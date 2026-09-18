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

namespace Swoolefy\Support\Workflow\Exception;

use Throwable;

/**
 * Run 快照 revision / status+revision CAS 条件不匹配。
 *
 * 立即 Abort 当前 Runtime，禁止自动重试、禁止重读后再跑 DAG。
 */
final class WorkflowRuntimeConflictException extends WorkflowRuntimeException
{
    public function __construct(
        public readonly string $runId,
        public readonly string $workflowId,
        public readonly int $expectedRevision,
        public readonly string $workflowVersion = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'workflow_runtime_conflict run_id=%s workflow_id=%s workflow_version=%s expected_revision=%d',
                $runId,
                $workflowId,
                $workflowVersion,
                $expectedRevision,
            ),
            0,
            $previous,
        );
    }
}
