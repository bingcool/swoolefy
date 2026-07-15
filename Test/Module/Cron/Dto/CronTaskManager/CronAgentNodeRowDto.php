<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron Agent 节点行 DTO。
 *
 * **职责**：表示 cron_agent_node 表的单条节点记录，字段与数据库查询结果对齐（camelCase 输出）。
 *
 * **生产者**：{@see \Test\Module\Cron\Response\CronTaskManager\CronNodeRowResponse} 与
 * {@see \Test\Module\Cron\Response\CronTaskManager\CronNodeListResponse} 通过
 * {@see static::fromEntityRow} 将 Service 返回的实体属性映射为 DTO。
 *
 * **消费者**：Controller 将 DTO 包装进 Response 后由 API 层序列化输出。
 *
 * **关键字段语义**：
 * - nodeName / nodeIp：Agent 节点的标识信息，创建时必填
 * - remark：可选备注
 */
class CronAgentNodeRowDto extends AbstractDto
{
    #[ApiProperty(description: '节点 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '节点名称')]
    protected string $nodeName = '';

    #[ApiProperty(description: '节点 IP')]
    protected string $nodeIp = '';

    #[ApiProperty(description: '备注')]
    protected string $remark = '';

    #[ApiProperty(description: '创建时间')]
    protected string $createdAt = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updatedAt = '';

    /**
     * 从数据库实体行（snake_case）映射为 DTO。
     *
     * @param array<string, mixed> $row cron_agent_node 查询行或实体 getAttributes() 结果
     */
    public static function fromEntityRow(array $row): self
    {
        $dto = new self();
        $dto->setId((int)($row['id'] ?? 0));
        $dto->setNodeName((string)($row['node_name'] ?? ''));
        $dto->setNodeIp((string)($row['node_ip'] ?? ''));
        $dto->setRemark((string)($row['remark'] ?? ''));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        return $dto;
    }

    /** 获取节点 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 设置节点 ID */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /** 获取节点名称 */
    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    /** 设置节点名称 */
    public function setNodeName(string $nodeName): static
    {
        $this->nodeName = $nodeName;

        return $this;
    }

    /** 获取节点 IP */
    public function getNodeIp(): string
    {
        return $this->nodeIp;
    }

    /** 设置节点 IP */
    public function setNodeIp(string $nodeIp): static
    {
        $this->nodeIp = $nodeIp;

        return $this;
    }

    /** 获取备注 */
    public function getRemark(): string
    {
        return $this->remark;
    }

    /** 设置备注 */
    public function setRemark(string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }

    /** 获取创建时间 */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /** 设置创建时间 */
    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /** 获取更新时间 */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /** 设置更新时间 */
    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
