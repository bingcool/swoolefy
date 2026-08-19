<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Dashboard 聚合概览。
 */
class DashboardOverviewDto extends AbstractDto
{
    /**
     * @var array{total:int,enabled:int,disabled:int}
     */
    #[ApiProperty(description: '任务计数')]
    protected array $tasks = ['total' => 0, 'enabled' => 0, 'disabled' => 0];

    /**
     * @var array{today:int,success:int,failed:int,skipped:int}
     */
    #[ApiProperty(description: '今日执行计数')]
    protected array $executions = ['today' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];

    /**
     * @var array{total:int,online:int,offline:int}
     */
    #[ApiProperty(description: '节点计数；online 按各节点 heartbeat_interval 的存活公式判定')]
    protected array $nodes = ['total' => 0, 'online' => 0, 'offline' => 0];

    /**
     * @param array{total:int,enabled:int,disabled:int} $tasks
     * @param array{today:int,success:int,failed:int,skipped:int} $executions
     * @param array{total:int,online:int,offline:int} $nodes
     */
    public static function of(array $tasks, array $executions, array $nodes): self
    {
        $dto = new self();
        $dto->tasks = $tasks;
        $dto->executions = $executions;
        $dto->nodes = $nodes;

        return $dto;
    }
}
