<?php

declare(strict_types=1);

namespace Test\Module\Cron;

use Test\Model\ClientModel;

/**
 * 手动执行请求表。独立于 cron_task，避免改 flag 触发 updated_at / fingerprint UPDATE。
 *
 * @property int $id
 * @property int $cron_id
 * @property string $requested_at
 * @property string|null $consumed_at
 */
class CronTaskRunRequestEntity extends ClientModel
{
    protected static $table = 'cron_task_run_request';

    protected $pk = 'id';
}
