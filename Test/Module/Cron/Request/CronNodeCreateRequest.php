<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronNodeCreateRequest extends BaseRequest
{
    #[ApiProperty(description: '节点名称')]
    #[ValidationRule(rule: 'required|string', message: 'node_name不能为空')]
    protected string $node_name = '';

    #[ApiProperty(description: '节点 IP')]
    #[ValidationRule(rule: 'required|string', message: 'node_ip不能为空')]
    protected string $node_ip = '';

    #[ApiProperty(description: '备注')]
    protected ?string $remark = null;

    public function getNodeName(): string
    {
        return trim($this->node_name);
    }

    public function setNodeName(string $node_name): self
    {
        $this->node_name = $node_name;

        return $this;
    }

    public function getNodeIp(): string
    {
        return trim($this->node_ip);
    }

    public function setNodeIp(string $node_ip): self
    {
        $this->node_ip = $node_ip;

        return $this;
    }

    public function getRemark(): string
    {
        return trim((string)($this->remark ?? ''));
    }

    public function setRemark(?string $remark): self
    {
        $this->remark = $remark;

        return $this;
    }
}
