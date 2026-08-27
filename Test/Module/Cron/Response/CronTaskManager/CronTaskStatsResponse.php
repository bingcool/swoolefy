<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskStatsResultDto;

class CronTaskStatsResponse extends BaseResponse
{
    #[ApiProperty(description: '任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '执行记录总条数')]
    protected int $total = 0;

    #[ApiProperty(description: 'register（注册定时任务）次数')]
    protected int $register = 0;

    #[ApiProperty(description: 'running 次数')]
    protected int $running = 0;

    #[ApiProperty(description: '成功次数')]
    protected int $success = 0;

    #[ApiProperty(description: '失败次数')]
    protected int $failed = 0;

    #[ApiProperty(description: '跳过次数')]
    protected int $skipped = 0;

    #[ApiProperty(description: '超时次数')]
    protected int $timeout = 0;

    #[ApiProperty(description: '取消次数')]
    protected int $cancelled = 0;

    #[ApiProperty(description: '已结束次数')]
    protected int $finished = 0;

    #[ApiProperty(description: '成功率分母（不含 skipped）')]
    protected int $attempted = 0;

    #[ApiProperty(description: '成功率（百分比）；分母为 attempted')]
    protected float $successRate = 0.0;

    #[ApiProperty(description: 'SUCCESS 行平均耗时（毫秒）')]
    protected float $avgDurationMs = 0.0;

    #[ApiProperty(description: 'SUCCESS 行最大耗时（毫秒）')]
    protected float $maxDurationMs = 0.0;

    #[ApiProperty(description: '参与耗时统计的 SUCCESS 行数')]
    protected int $samples = 0;

    public static function fromDto(CronTaskStatsResultDto $stats): self
    {
        $response = new self();
        $response->taskId = $stats->getTaskId();
        $response->total = $stats->getTotal();
        $response->register = $stats->getRegister();
        $response->running = $stats->getRunning();
        $response->success = $stats->getSuccess();
        $response->failed = $stats->getFailed();
        $response->skipped = $stats->getSkipped();
        $response->timeout = $stats->getTimeout();
        $response->cancelled = $stats->getCancelled();
        $response->finished = $stats->getFinished();
        $response->attempted = $stats->getAttempted();
        $response->successRate = $stats->getSuccessRate();
        $response->avgDurationMs = $stats->getAvgDurationMs();
        $response->maxDurationMs = $stats->getMaxDurationMs();
        $response->samples = $stats->getSamples();

        return $response;
    }

    public function getData(): array
    {
        return [
            'taskId' => $this->taskId,
            'total' => $this->total,
            'register' => $this->register,
            'running' => $this->running,
            'success' => $this->success,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'timeout' => $this->timeout,
            'cancelled' => $this->cancelled,
            'finished' => $this->finished,
            'attempted' => $this->attempted,
            'successRate' => $this->successRate,
            'avgDurationMs' => $this->avgDurationMs,
            'maxDurationMs' => $this->maxDurationMs,
            'samples' => $this->samples,
        ];
    }
}
