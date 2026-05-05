<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronAgentReportAckResponse extends BaseResponse
{
    #[ApiProperty(description: '是否已保存')]
    protected bool $saved;

    #[ApiProperty(description: 'Cron 任务 ID')]
    protected int $cron_id;

    public function __construct(int $cron_id, bool $saved = true)
    {
        $this->setCronId($cron_id);
        $this->setSaved($saved);
    }

    public function getSaved(): bool
    {
        return $this->saved;
    }

    public function setSaved(bool $saved): self
    {
        $this->saved = $saved;

        return $this;
    }

    public function getCronId(): int
    {
        return $this->cron_id;
    }

    public function setCronId(int $cron_id): self
    {
        $this->cron_id = $cron_id;

        return $this;
    }

    public function getData(): array
    {
        return [
            'saved' => $this->getSaved(),
            'cron_id' => $this->getCronId(),
        ];
    }
}
