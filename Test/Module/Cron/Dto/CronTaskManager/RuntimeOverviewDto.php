<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Runtime 聚合。Cron Worker 与 HTTP Worker 进程隔离，running / lastSuccessAt
 * 仅在本进程能读到 Cron 快照时才有值，否则如实返回 0 / null，不伪造。
 */
class RuntimeOverviewDto extends AbstractDto
{
    /**
     * @var array{jobs:int,enabled:int,running:int}
     */
    #[ApiProperty(description: '调度器计数')]
    protected array $scheduler = ['jobs' => 0, 'enabled' => 0, 'running' => 0];

    /**
     * @var array{lastSuccessAt:?string,lastErrorAt:?string,processLocal:bool}
     */
    #[ApiProperty(description: '配置同步；processLocal=true 表示仅本进程可见')]
    protected array $sync = ['lastSuccessAt' => null, 'lastErrorAt' => null, 'processLocal' => true];

    /**
     * @var array{online:int,offline:int}
     */
    #[ApiProperty(description: '节点在线情况（Worker 心跳落库 + 各节点 interval 存活公式）')]
    protected array $nodes = ['online' => 0, 'offline' => 0];

    #[ApiProperty(description: '说明：HTTP Worker 通常读不到 Cron Worker 诊断')]
    protected string $note = '';

    /**
     * @param array{jobs:int,enabled:int,running:int} $scheduler
     * @param array{lastSuccessAt:?string,lastErrorAt:?string,processLocal:bool} $sync
     * @param array{online:int,offline:int} $nodes
     */
    public static function of(array $scheduler, array $sync, array $nodes, string $note): self
    {
        $dto = new self();
        $dto->scheduler = $scheduler;
        $dto->sync = $sync;
        $dto->nodes = $nodes;
        $dto->note = $note;

        return $dto;
    }
}
