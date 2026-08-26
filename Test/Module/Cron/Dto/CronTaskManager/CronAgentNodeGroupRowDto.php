<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron Agent 节点分组行 DTO。
 *
 * **生产者**：{@see \Test\Module\Cron\Response\CronTaskManager\CronNodeGroupRowResponse} 与
 * {@see \Test\Module\Cron\Response\CronTaskManager\CronNodeGroupListResponse} 通过
 * {@see static::fromEntityRow} 映射。
 *
 * **关键字段语义**：
 * - id / groupId：均来自表主键 id（分组表无 group_id 列）
 * - groupName：分组显示名称，创建时必填且唯一
 * - nodeCount：该分组下未软删节点数
 */
class CronAgentNodeGroupRowDto extends AbstractDto
{
    #[ApiProperty(description: '分组 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '分组 ID（与 id 相同，由 cron_agent_node_group.id 映射，非表列 group_id）')]
    protected int $groupId = 0;

    #[ApiProperty(description: '分组名称')]
    protected string $groupName = '';

    #[ApiProperty(description: '备注')]
    protected string $remark = '';

    #[ApiProperty(description: '分组下未软删节点数')]
    protected int $nodeCount = 0;

    #[ApiProperty(description: '创建时间')]
    protected string $createdAt = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updatedAt = '';

    /**
     * @param array<string, mixed> $row cron_agent_node_group 查询行或实体 getAttributes() 结果
     */
    public static function fromEntityRow(array $row): self
    {
        $dto = new self();
        $id = (int)($row['id'] ?? 0);
        $dto->setId($id);
        $dto->setGroupId($id);
        $dto->setGroupName((string)($row['group_name'] ?? ''));
        $dto->setRemark((string)($row['remark'] ?? ''));
        $dto->setNodeCount((int)($row['node_count'] ?? 0));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        return $dto;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

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

    public function getNodeCount(): int
    {
        return $this->nodeCount;
    }

    public function setNodeCount(int $nodeCount): static
    {
        $this->nodeCount = $nodeCount;

        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
