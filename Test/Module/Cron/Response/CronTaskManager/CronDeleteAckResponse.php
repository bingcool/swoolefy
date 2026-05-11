<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronDeleteAckResponse extends BaseResponse
{
    #[ApiProperty(description: '记录 ID')]
    protected int $id;

    #[ApiProperty(description: '是否已删除')]
    protected bool $deleted;

    public function __construct(int $id, bool $deleted = true)
    {
        $this->setId($id);
        $this->setDeleted($deleted);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getData(): array
    {
        return [
            'id' => $this->getId(),
            'deleted' => $this->getDeleted(),
        ];
    }
}
