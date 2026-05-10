<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BasePageRequest;

/**
 * 任务列表查询请求（分页参数由BasePageRequest提供）
 */
class CronTaskListQueryRequest extends BasePageRequest
{
    #[ApiProperty(description: '名称关键词')]
    protected ?string $keyword = null;

    #[ApiProperty(description: '任务状态：0=禁用, 1=启用')]
    protected ?int $status = null;

    #[ApiProperty(description: '节点ID')]
    protected ?int $nodeId = null;

    #[ApiProperty(description: '执行类型：1=shell, 2=http')]
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
