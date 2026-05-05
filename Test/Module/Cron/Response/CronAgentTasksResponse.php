<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronAgentTasksResponse extends BaseResponse
{
    #[ApiProperty(description: '节点 ID')]
    protected int $node_id;

    #[ApiProperty(description: '任务条数')]
    protected int $total;

    #[ApiProperty(description: '执行类型（单类型查询时返回）')]
    protected ?int $exec_type = null;

    /**
     * @var array<int, mixed>|null
     */
    #[ApiProperty(description: '任务列表（指定 exec_type 时）')]
    protected ?array $list = null;

    /**
     * @var array<int, mixed>|null
     */
    #[ApiProperty(description: 'Shell 任务列表')]
    protected ?array $shell_tasks = null;

    /**
     * @var array<int, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 任务列表')]
    protected ?array $http_tasks = null;

    /**
     * @param array<int, mixed> $list
     */
    public static function forExecType(int $node_id, int $exec_type, array $list): self
    {
        $self = new self();
        $self->setNodeId($node_id);
        $self->setExecType($exec_type);
        $self->setList($list);
        $self->setTotal(count($list));
        $self->setShellTasks(null);
        $self->setHttpTasks(null);

        return $self;
    }

    /**
     * @param array<int, mixed> $shell_tasks
     * @param array<int, mixed> $http_tasks
     */
    public static function forAllTypes(int $node_id, array $shell_tasks, array $http_tasks): self
    {
        $self = new self();
        $self->setNodeId($node_id);
        $self->setExecType(null);
        $self->setList(null);
        $self->setShellTasks($shell_tasks);
        $self->setHttpTasks($http_tasks);
        $self->setTotal(count($shell_tasks) + count($http_tasks));

        return $self;
    }

    public function getNodeId(): int
    {
        return $this->node_id;
    }

    public function setNodeId(int $node_id): self
    {
        $this->node_id = $node_id;

        return $this;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setTotal(int $total): self
    {
        $this->total = $total;

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

    /**
     * @return array<int, mixed>|null
     */
    public function getList(): ?array
    {
        return $this->list;
    }

    /**
     * @param array<int, mixed>|null $list
     */
    public function setList(?array $list): self
    {
        $this->list = $list;

        return $this;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getShellTasks(): ?array
    {
        return $this->shell_tasks;
    }

    /**
     * @param array<int, mixed>|null $shell_tasks
     */
    public function setShellTasks(?array $shell_tasks): self
    {
        $this->shell_tasks = $shell_tasks;

        return $this;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getHttpTasks(): ?array
    {
        return $this->http_tasks;
    }

    /**
     * @param array<int, mixed>|null $http_tasks
     */
    public function setHttpTasks(?array $http_tasks): self
    {
        $this->http_tasks = $http_tasks;

        return $this;
    }

    public function getData(): array
    {
        if ($this->getList() !== null) {
            return [
                'node_id' => $this->getNodeId(),
                'exec_type' => $this->getExecType(),
                'total' => $this->getTotal(),
                'list' => $this->getList(),
            ];
        }

        return [
            'node_id' => $this->getNodeId(),
            'total' => $this->getTotal(),
            'shell_tasks' => $this->getShellTasks() ?? [],
            'http_tasks' => $this->getHttpTasks() ?? [],
        ];
    }
}
