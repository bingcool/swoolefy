<?php

declare(strict_types=1);

namespace Test\Module\Cron\Entity;

use Test\Model\ClientModel;

/**
 * 手动执行请求表。独立于 cron_task，避免改 flag 触发 updated_at / fingerprint UPDATE。
 *
 * @property int $id
 * @property int $cron_id
 * @property string $requested_at
 * @property string|null $consumed_at
 * @property string created_at 创建时间
 * @property string updated_at 修改时间
 * @property string deleted_at 删除时间
 */
class CronTaskRunRequestEntity extends ClientModel
{
    protected static $table = 'cron_task_run_request';

    protected $pk = 'id';
}
