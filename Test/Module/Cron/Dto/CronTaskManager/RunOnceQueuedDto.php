<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 手动执行入队回执。不声称已执行，只表示 Cron Worker 下次 Polling 会消费。
 */
class RunOnceQueuedDto extends AbstractDto
{
    #[ApiProperty(description: '任务 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '是否已入队')]
    protected bool $queued = true;

    #[ApiProperty(description: '入队时间')]
    protected string $requestedAt = '';

    #[ApiProperty(description: '说明')]
    protected string $message = '';

    public static function of(int $id, string $requestedAt): self
    {
        $dto = new self();
        $dto->id = $id;
        $dto->queued = true;
        $dto->requestedAt = $requestedAt;
        $dto->message = '已入队，等待 Cron Worker 下次 Polling 执行 runOnceNow，不改 expression / nextRunAt';

        return $dto;
    }
}
