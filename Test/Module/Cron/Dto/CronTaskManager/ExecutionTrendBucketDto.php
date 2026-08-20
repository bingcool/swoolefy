<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 趋势时间桶。
 */
class ExecutionTrendBucketDto extends AbstractDto
{
    #[ApiProperty(description: '桶标签，如 02:00 或 2026-08-17')]
    protected string $time = '';

    #[ApiProperty(description: '总次数')]
    protected int $total = 0;

    #[ApiProperty(description: '成功次数')]
    protected int $success = 0;

    #[ApiProperty(description: '失败次数')]
    protected int $failed = 0;

    #[ApiProperty(description: '超时次数')]
    protected int $timeout = 0;

    #[ApiProperty(description: '跳过次数')]
    protected int $skipped = 0;

    public static function of(string $time, int $total, int $success, int $failed, int $timeout = 0, int $skipped = 0): self
    {
        $dto = new self();
        $dto->time = $time;
        $dto->total = $total;
        $dto->success = $success;
        $dto->failed = $failed;
        $dto->timeout = $timeout;
        $dto->skipped = $skipped;

        return $dto;
    }
}
