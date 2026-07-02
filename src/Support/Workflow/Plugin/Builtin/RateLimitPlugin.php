<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Plugin\Builtin;

use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowRateLimitException;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;

/**
 * Run 级并发限流插件 —— 控制同时执行的 Workflow Run 数量。
 *
 * 钩子时序：
 *   run.start    → activeRuns++，超限抛 WorkflowRateLimitException
 *   run.complete → activeRuns--（含 FAILED / COMPENSATED / WAITING 结束路径）
 *
 * 技术要点：
 * - 默认进程内计数，适合单 Worker 单测
 * - 生产多 Worker 应换 Redis 信号量（WORKFLOW_MAX_CONCURRENT_RUNS 全局生效）
 * - 与 HTTP RateLimiterMiddleware 独立：本插件限 Workflow Run，不限普通 API
 *
 * @see swoolefyAI.md §4.12 RateLimitPlugin
 */
final class RateLimitPlugin implements WorkflowPluginInterface
{
    /** 当前占用槽位的 Run 数。 */
    private int $activeRuns = 0;

    /** @var list<string> 占用槽位的 runId（便于调试泄漏） */
    private array $heldRunIds = [];

    /**
     * @param int $maxConcurrent 最大并发 Run 数，0 表示拒绝一切新 Run
     */
    public function __construct(
        private readonly int $maxConcurrent = 50,
    ) {
    }

    /** Fluent 工厂，便于 Definition->plugins(RateLimitPlugin::make(50))。 */
    public static function make(int $maxConcurrent = 50): self
    {
        return new self($maxConcurrent);
    }

    /** {@inheritdoc} */
    public function name(): string
    {
        return 'rate_limit';
    }

    /** 当前活跃 Run 数（MetricsPlugin / 单测观测）。 */
    public function activeRuns(): int
    {
        return $this->activeRuns;
    }

    /** {@inheritdoc} */
    public function register(PluginRegistry $registry): void
    {
        $registry->onRunStart(function (WorkflowRun $run, array $input): void {
            unset($input);
            if ($this->activeRuns >= $this->maxConcurrent) {
                throw new WorkflowRateLimitException(
                    "Workflow concurrent run limit exceeded ({$this->maxConcurrent})",
                );
            }
            $this->activeRuns++;
            $this->heldRunIds[] = $run->runId;
        });

        $registry->onRunComplete(function (WorkflowRun $run): void {
            if ($this->activeRuns <= 0) {
                return;
            }
            $this->activeRuns--;
            $key = array_search($run->runId, $this->heldRunIds, true);
            if ($key !== false) {
                unset($this->heldRunIds[$key]);
                $this->heldRunIds = array_values($this->heldRunIds);
            }
        });
    }
}
