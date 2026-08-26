<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 更新节点分组入参。
 */
class UpdateNodeGroupDto extends AbstractDto
{
    #[ApiProperty(description: '分组 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '分组名称')]
    protected string $groupName = '';

    #[ApiProperty(description: '备注')]
    protected string $remark = '';

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function setGroupName(string $groupName): static
    {
        $this->groupName = $groupName;

        return $this;
    }

    public function getRemark(): string
    {
        return $this->remark;
    }

    public function setRemark(string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
