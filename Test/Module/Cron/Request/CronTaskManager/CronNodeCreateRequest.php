<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronNodeCreateRequest extends BaseRequest
{
    #[ApiProperty(description: '节点名称')]
    #[ValidationRule(rule: 'required|string', message: 'nodeName 不能为空')]
    protected string $nodeName = '';

    #[ApiProperty(description: '节点 IP')]
    #[ValidationRule(rule: 'required|string', message: 'nodeIp 不能为空')]
    protected string $nodeIp = '';

    #[ApiProperty(description: '所属分组 ID')]
    #[ValidationRule(rule: 'required|int', message: 'groupId 不能为空')]
    #[StringToInt]
    protected int $groupId = 0;

    #[ApiProperty(description: '备注')]
    protected ?string $remark = null;

    public function getNodeName(): string
    {
        return trim($this->nodeName);
    }

    public function setNodeName(string $nodeName): static
    {
        $this->nodeName = $nodeName;

        return $this;
    }

    public function getNodeIp(): string
    {
        return trim($this->nodeIp);
    }

    public function setNodeIp(string $nodeIp): static
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

    public function getRemark(): string
    {
        return trim((string)($this->remark ?? ''));
    }

    public function setRemark(?string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }
}
