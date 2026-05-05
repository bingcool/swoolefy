<?php

declare(strict_types=1);

namespace Test\Module\Cron\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

class CronAgentHeartbeatResponse extends BaseResponse
{
    #[ApiProperty(description: '节点 ID')]
    protected int $node_id;

    #[ApiProperty(description: '是否存活')]
    protected bool $alive;

    #[ApiProperty(description: '服务端当前时间')]
    protected string $server_time;

    public function __construct(int $node_id, string $server_time)
    {
        $this->setNodeId($node_id);
        $this->setAlive(true);
        $this->setServerTime($server_time);
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

    public function getAlive(): bool
    {
        return $this->alive;
    }

    public function setAlive(bool $alive): self
    {
        $this->alive = $alive;

        return $this;
    }

    public function getServerTime(): string
    {
        return $this->server_time;
    }

    public function setServerTime(string $server_time): self
    {
        $this->server_time = $server_time;

        return $this;
    }

    public function getData(): array
    {
        return [
            'node_id' => $this->getNodeId(),
            'alive' => $this->getAlive(),
            'server_time' => $this->getServerTime(),
        ];
    }
}
