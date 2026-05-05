<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Http\BasePageRequest;

/**
 * 任务列表查询（分页参数可省略，使用默认值）。
 */
class CronTaskListQueryRequest extends BasePageRequest
{
    #[ApiProperty(description: '名称关键词')]
    protected ?string $keyword = null;

    #[ApiProperty(description: '任务状态')]
    protected ?int $status = null;

    #[ApiProperty(description: '节点 ID')]
    #[StringToInt]
    protected ?int $node_id = null;

    #[ApiProperty(description: '执行类型：1 shell，2 http')]
    protected ?int $exec_type = null;

    public function getKeyword(): string
    {
        return trim((string)($this->keyword ?? ''));
    }

    public function setKeyword(?string $keyword): self
    {
        $this->keyword = $keyword;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getNodeId(): ?int
    {
        return $this->node_id;
    }

    public function setNodeId(?int $node_id): self
    {
        $this->node_id = $node_id;

        return $this;
    }

    public function getExecType(): ?int
    {
        return $this->exec_type;
    }

    public function setExecType(?int $exec_type): self
    {
        $this->exec_type = $exec_type;

        return $this;
    }
}
