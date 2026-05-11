<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronAgentHeartbeatResponse extends BaseResponse
{
    #[ApiProperty(description: '节点 ID')]
    protected int $nodeId;

    #[ApiProperty(description: '是否存活')]
    protected bool $alive;

    #[ApiProperty(description: '服务端当前时间')]
    protected string $serverTime;

    public function __construct(int $nodeId, string $serverTime)
    {
        $this->setNodeId($nodeId);
        $this->setAlive(true);
        $this->setServerTime($serverTime);
    }

    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    public function getAlive(): bool
    {
        return $this->alive;
    }

    public function setAlive(bool $alive): static
    {
        $this->alive = $alive;

        return $this;
    }

    public function getServerTime(): string
    {
        return $this->serverTime;
    }

    public function setServerTime(string $serverTime): static
    {
        $this->serverTime = $serverTime;

        return $this;
    }

    public function getData(): array
    {
        return [
            'nodeId' => $this->getNodeId(),
            'alive' => $this->getAlive(),
            'serverTime' => $this->getServerTime(),
        ];
    }
}
