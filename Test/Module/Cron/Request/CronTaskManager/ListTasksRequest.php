<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BasePageRequest;

/**
 * 任务列表查询（分页由 BasePageRequest 提供）。
 */
class ListTasksRequest extends BasePageRequest
{
    #[ApiProperty(description: '名称关键词')]
    #[ValidationRule(rule: 'nullable|string', message: 'keyword 格式错误')]
    protected ?string $keyword = null;

    #[ApiProperty(description: '任务状态：0=禁用, 1=启用')]
    #[ValidationRule(rule: 'nullable|int', message: 'status 必须是整数')]
    #[StringToInt]
    protected ?int $status = null;

    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'nullable|int', message: 'nodeId 必须是整数')]
    #[StringToInt]
    protected ?int $nodeId = null;

    #[ApiProperty(description: '节点分组 ID；-1 表示未分组节点')]
    #[ValidationRule(rule: 'nullable|int', message: 'groupId 必须是整数')]
    #[StringToInt]
    protected ?int $groupId = null;

    #[ApiProperty(description: '执行类型：1=shell, 2=http')]
    #[ValidationRule(rule: 'nullable|int', message: 'execType 必须是整数')]
    #[StringToInt]
    protected ?int $execType = null;

    public function getKeyword(): ?string
    {
        return $this->keyword;
    }

    public function setKeyword(?string $keyword): static
    {
        $this->keyword = $keyword;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getNodeId(): ?int
    {
        return $this->nodeId;
    }

    public function setNodeId(?int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    public function getGroupId(): ?int
    {
        return $this->groupId;
    }

    public function setGroupId(?int $groupId): static
    {
        $this->groupId = $groupId;

        return $this;
    }

    public function getExecType(): ?int
    {
        return $this->execType;
    }

    public function setExecType(?int $execType): static
    {
        $this->execType = $execType;

        return $this;
    }
}
