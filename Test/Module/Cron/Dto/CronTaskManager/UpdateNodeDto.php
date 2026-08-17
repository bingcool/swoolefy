<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 更新节点入参。
 */
class UpdateNodeDto extends AbstractDto
{
    #[ApiProperty(description: '节点 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '节点名称')]
    protected ?string $nodeName = null;

    #[ApiProperty(description: '节点 IP')]
    protected ?string $nodeIp = null;

    #[ApiProperty(description: '备注')]
    protected ?string $remark = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getNodeName(): ?string
    {
        return $this->nodeName;
    }

    public function setNodeName(?string $nodeName): static
    {
        $this->nodeName = $nodeName;

        return $this;
    }

    public function getNodeIp(): ?string
    {
        return $this->nodeIp;
    }

    public function setNodeIp(?string $nodeIp): static
    {
        $this->nodeIp = $nodeIp;

        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
