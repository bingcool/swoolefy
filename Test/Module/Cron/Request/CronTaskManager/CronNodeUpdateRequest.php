<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

/**
 * 更新节点（部分字段）。id 走 query/body，对应架构 PUT /nodes/{id}。
 */
class CronNodeUpdateRequest extends BaseRequest
{
    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'required|int', message: 'id 不能为空')]
    #[StringToInt]
    protected int $id = 0;

    #[ApiProperty(description: '节点名称')]
    protected ?string $nodeName = null;

    #[ApiProperty(description: '节点 IP')]
    protected ?string $nodeIp = null;

    #[ApiProperty(description: '所属分组 ID')]
    #[ValidationRule(rule: 'required|int', message: 'groupId 不能为空')]
    #[StringToInt]
    protected int $groupId = 0;

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
        return $this->nodeName !== null ? trim($this->nodeName) : null;
    }

    public function setNodeName(?string $nodeName): static
    {
        $this->nodeName = $nodeName;

        return $this;
    }

    public function getNodeIp(): ?string
    {
        return $this->nodeIp !== null ? trim($this->nodeIp) : null;
    }

    public function setNodeIp(?string $nodeIp): static
    {
        $this->nodeIp = $nodeIp;

        return $this;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function setGroupId(int $groupId): static
    {
        $this->groupId = $groupId;

        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark !== null ? trim($this->remark) : null;
    }

    public function setRemark(?string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
