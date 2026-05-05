<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronTaskStatusAckResponse extends BaseResponse
{
    #[ApiProperty(description: '任务 ID')]
    protected int $id;

    #[ApiProperty(description: '状态：0 禁用，1 启用')]
    protected int $status;

    public function __construct(int $id, int $status)
    {
        $this->setId($id);
        $this->setStatus($status);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getData(): array
    {
        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
        ];
    }
}
