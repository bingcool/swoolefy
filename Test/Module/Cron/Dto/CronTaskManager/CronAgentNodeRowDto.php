<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Worker\Cron\CronNodeLiveness;

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
 * - groupId / groupName：所属分组；历史未分组时 groupId=0、groupName 为空
 * - remark：可选备注
 * - status：online | offline（由 {@see CronNodeLiveness} 按该节点 heartbeat_interval 判定）
 * - lastHeartbeatAt / heartbeatInterval / staleAfterSeconds：心跳与存活阈值
 */
class CronAgentNodeRowDto extends AbstractDto
{
    #[ApiProperty(description: '节点 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '节点名称')]
    protected string $nodeName = '';

    #[ApiProperty(description: '节点 IP')]
    protected string $nodeIp = '';

    #[ApiProperty(description: '所属分组 ID；未分组时为 0')]
    protected int $groupId = 0;

    #[ApiProperty(description: '所属分组名称；未分组时为空')]
    protected string $groupName = '';

    #[ApiProperty(description: '备注')]
    protected string $remark = '';

    #[ApiProperty(description: '最近心跳时间；从未心跳时为空')]
    protected string $lastHeartbeatAt = '';

    #[ApiProperty(description: '该节点心跳间隔（秒），Ack 时由 Worker 写入')]
    protected int $heartbeatInterval = CronNodeLiveness::DEFAULT_INTERVAL;

    #[ApiProperty(description: '超过该秒数未心跳则 offline：max(3*interval, interval+5)')]
    protected int $staleAfterSeconds = 45;

    #[ApiProperty(description: 'online / offline（从未心跳视为 offline）')]
    protected string $status = CronNodeLiveness::STATUS_OFFLINE;

    #[ApiProperty(description: '绑定任务数')]
    protected int $taskCount = 0;

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
        $dto->setGroupId((int)($row['group_id'] ?? 0));
        $dto->setGroupName((string)($row['group_name'] ?? ''));
        $dto->setRemark((string)($row['remark'] ?? ''));
        $lastHb = (string)($row['last_heartbeat_at'] ?? '');
        $interval = CronNodeLiveness::normalizeInterval((int)($row['heartbeat_interval'] ?? 0));
        $now = time();
        $dto->setLastHeartbeatAt($lastHb);
        $dto->setHeartbeatInterval($interval);
        $dto->setStaleAfterSeconds(CronNodeLiveness::staleAfterSeconds($interval));
        $dto->setStatus(self::deriveHeartbeatStatus($lastHb, $now, $interval));
        $dto->setTaskCount((int)($row['task_count'] ?? 0));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        return $dto;
    }

    /**
     * 按该节点自己的心跳间隔判定 online / offline。
     * 从未心跳或无法解析时间为 offline，不返回 unknown。
     *
     * @param string $lastHeartbeatAt DB datetime 或 unix 秒字符串
     * @param int|null $now 当前 unix 秒，缺省 time()
     * @param int $interval 该节点 heartbeat_interval（秒）
     */
    public static function deriveHeartbeatStatus(
        string $lastHeartbeatAt,
        ?int $now = null,
        int $interval = CronNodeLiveness::DEFAULT_INTERVAL,
    ): string {
        return CronNodeLiveness::status(
            $now ?? time(),
            CronNodeLiveness::parseHeartbeatAt($lastHeartbeatAt),
            $interval,
        );
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

    public function getLastHeartbeatAt(): string
    {
        return $this->lastHeartbeatAt;
    }

    public function setLastHeartbeatAt(string $lastHeartbeatAt): static
    {
        $this->lastHeartbeatAt = $lastHeartbeatAt;

        return $this;
    }

    public function getHeartbeatInterval(): int
    {
        return $this->heartbeatInterval;
    }

    public function setHeartbeatInterval(int $heartbeatInterval): static
    {
        $this->heartbeatInterval = $heartbeatInterval;

        return $this;
    }

    public function getStaleAfterSeconds(): int
    {
        return $this->staleAfterSeconds;
    }

    public function setStaleAfterSeconds(int $staleAfterSeconds): static
    {
        $this->staleAfterSeconds = $staleAfterSeconds;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getTaskCount(): int
    {
        return $this->taskCount;
    }

    public function setTaskCount(int $taskCount): static
    {
        $this->taskCount = $taskCount;

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
