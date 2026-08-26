<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 定时任务列表分页查询入参 DTO。
 *
 * **职责**：封装列表查询条件，供 Service 层构建数据库查询，不依赖 HTTP Request。
 *
 * **生产者**：{@see \Test\Module\Cron\Controller\CronTaskManagerController::listTasks} 从
 * {@see \Test\Module\Cron\Request\CronTaskManager\ListTasksRequest} 映射字段后构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::listTasks} 读取过滤条件
 * 与分页参数，返回 {@see \Test\Module\Cron\Response\CronTaskManager\ListTasksPageResult}。
 *
 * **关键字段语义**：
 * - page / pageSize：分页；setter 自动校正为 ≥1
 * - keyword：任务名称模糊搜索（name LIKE）
 * - status：0=禁用，1=启用；null 表示不过滤
 * - nodeId：绑定 Agent 节点 ID；null 表示不过滤
 * - groupId：节点所属分组 ID；-1 表示仅未分组节点；null 表示不过滤
 * - execType：1=shell，2=http；null 表示不过滤
 */
class ListTasksQueryDto extends AbstractDto
{
    #[ApiProperty(description: '当前页码，从 1 开始')]
    protected int $page = 1;

    #[ApiProperty(description: '每页条数')]
    protected int $pageSize = 20;

    #[ApiProperty(description: '任务名称关键词（模糊匹配），null 表示不按名称过滤')]
    protected ?string $keyword = null;

    #[ApiProperty(description: '任务状态：0=禁用，1=启用；null 表示不过滤')]
    protected ?int $status = null;

    #[ApiProperty(description: '绑定的 Agent 节点 ID；null 表示不过滤')]
    protected ?int $nodeId = null;

    #[ApiProperty(description: '节点分组 ID；-1 表示未分组节点；null 表示不过滤')]
    protected ?int $groupId = null;

    #[ApiProperty(description: '执行类型：1=shell，2=http；null 表示不过滤')]
    protected ?int $execType = null;

    /** 获取当前页码 */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * 设置当前页码（自动校正为 ≥1）。
     */
    public function setPage(int $page): static
    {
        $this->page = max(1, $page);

        return $this;
    }

    /** 获取每页条数 */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * 设置每页条数（自动校正为 ≥1）。
     */
    public function setPageSize(int $pageSize): static
    {
        $this->pageSize = max(1, $pageSize);

        return $this;
    }

    /** 获取名称关键词 */
    public function getKeyword(): ?string
    {
        return $this->keyword;
    }

    /** 设置名称关键词 */
    public function setKeyword(?string $keyword): static
    {
        $this->keyword = $keyword;

        return $this;
    }

    /** 获取状态过滤条件 */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /** 设置状态过滤条件 */
    public function setStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** 获取节点 ID 过滤条件 */
    public function getNodeId(): ?int
    {
        return $this->nodeId;
    }

    /** 设置节点 ID 过滤条件 */
    public function setNodeId(?int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    /** 获取节点分组 ID 过滤条件 */
    public function getGroupId(): ?int
    {
        return $this->groupId;
    }

    /** 设置节点分组 ID 过滤条件 */
    public function setGroupId(?int $groupId): static
    {
        $this->groupId = $groupId;

        return $this;
    }

    /** 获取执行类型过滤条件 */
    public function getExecType(): ?int
    {
        return $this->execType;
    }

    /** 设置执行类型过滤条件 */
    public function setExecType(?int $execType): static
    {
        $this->execType = $execType;

        return $this;
    }

    /**
     * 计算 SQL LIMIT 偏移量：(page - 1) × pageSize。
     */
    public function getOffset(): int
    {
        return ($this->getPage() - 1) * $this->getPageSize();
    }
}
