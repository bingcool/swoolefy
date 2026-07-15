<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 任务执行统计结果 DTO。
 *
 * **职责**：承载对单条任务最近日志的粗算统计指标，供管理端看板展示。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::taskStats} 根据日志
 * message 关键词与耗时正则聚合计算。
 *
 * **消费者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::taskStats} 读取各
 * 指标字段并组装 {@see \Test\Module\Cron\Response\CronTaskManager\CronTaskStatsResponse}。
 *
 * **关键字段语义**：
 * - total：参与统计的日志条数（最多 2000）
 * - success / failed / skipped：按 message 关键词匹配计数（非严谨状态机）
 * - successRate：success / total × 100，保留两位小数
 * - avgDurationMs：从 message 提取耗时的平均值（毫秒）
 * - samples：成功提取到耗时值的日志条数
 */
class CronTaskStatsResultDto extends AbstractDto
{
    #[ApiProperty(description: '被统计的任务 ID')]
    protected int $taskId = 0;

    #[ApiProperty(description: '参与统计的日志总条数')]
    protected int $total = 0;

    #[ApiProperty(description: '判定为成功的次数')]
    protected int $success = 0;

    #[ApiProperty(description: '判定为失败的次数')]
    protected int $failed = 0;

    #[ApiProperty(description: '判定为跳过的次数')]
    protected int $skipped = 0;

    #[ApiProperty(description: '成功率（百分比，0–100）')]
    protected float $successRate = 0.0;

    #[ApiProperty(description: '平均执行耗时（毫秒）')]
    protected float $avgDurationMs = 0.0;

    #[ApiProperty(description: '成功提取耗时的样本数')]
    protected int $samples = 0;

    /** 获取任务 ID */
    public function getTaskId(): int
    {
        return $this->taskId;
    }

    /** 设置任务 ID */
    public function setTaskId(int $taskId): static
    {
        $this->taskId = $taskId;

        return $this;
    }

    /** 获取日志总条数 */
    public function getTotal(): int
    {
        return $this->total;
    }

    /** 设置日志总条数 */
    public function setTotal(int $total): static
    {
        $this->total = $total;

        return $this;
    }

    /** 获取成功次数 */
    public function getSuccess(): int
    {
        return $this->success;
    }

    /** 设置成功次数 */
    public function setSuccess(int $success): static
    {
        $this->success = $success;

        return $this;
    }

    /** 获取失败次数 */
    public function getFailed(): int
    {
        return $this->failed;
    }

    /** 设置失败次数 */
    public function setFailed(int $failed): static
    {
        $this->failed = $failed;

        return $this;
    }

    /** 获取跳过次数 */
    public function getSkipped(): int
    {
        return $this->skipped;
    }

    /** 设置跳过次数 */
    public function setSkipped(int $skipped): static
    {
        $this->skipped = $skipped;

        return $this;
    }

    /** 获取成功率 */
    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    /** 设置成功率 */
    public function setSuccessRate(float $successRate): static
    {
        $this->successRate = $successRate;

        return $this;
    }

    /** 获取平均耗时（毫秒） */
    public function getAvgDurationMs(): float
    {
        return $this->avgDurationMs;
    }

    /** 设置平均耗时（毫秒） */
    public function setAvgDurationMs(float $avgDurationMs): static
    {
        $this->avgDurationMs = $avgDurationMs;

        return $this;
    }

    /** 获取耗时样本数 */
    public function getSamples(): int
    {
        return $this->samples;
    }

    /** 设置耗时样本数 */
    public function setSamples(int $samples): static
    {
        $this->samples = $samples;

        return $this;
    }
}
