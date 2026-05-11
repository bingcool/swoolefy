<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronAgentReportAckResponse extends BaseResponse
{
    #[ApiProperty(description: '是否已保存')]
    protected bool $saved;

    #[ApiProperty(description: 'Cron 任务 ID')]
    protected int $cronId;

    public function __construct(int $cronId, bool $saved = true)
    {
        $this->setCronId($cronId);
        $this->setSaved($saved);
    }

    public function getSaved(): bool
    {
        return $this->saved;
    }

    public function setSaved(bool $saved): static
    {
        $this->saved = $saved;

        return $this;
    }

    public function getCronId(): int
    {
        return $this->cronId;
    }

    public function setCronId(int $cronId): static
    {
        $this->cronId = $cronId;

        return $this;
    }

    public function getData(): array
    {
        return [
            'saved' => $this->getSaved(),
            'cronId' => $this->getCronId(),
        ];
    }
}
