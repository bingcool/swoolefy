<?php
namespace Test\Module\Cron\Service;

use Swoolefy\Core\Schedule\ScheduleEvent;
use Test\Module\Cron\CronTaskEntity;

class CronTaskService {

    public function cronTaskList() {
        $list = CronTaskEntity::query()->where([
            'status' => 1
        ])->select()->toArray();
        $newTaskList = [];
        foreach ($list as $item) {
            $cronForkTask = ScheduleEvent::load($item);
            $cronForkTask->cron_meta_origin = ScheduleEvent::CRON_META_ORIGIN_DB;
            if (!empty($item['name'])) {
                $cronForkTask->cron_name = $item['name'];
            }

            if (!empty($item['expression'])) {
                $cronForkTask->cron_expression = $item['expression'];
            }

            if (!empty($item['exec_script']) && (str_contains($item['exec_script'], 'script.php') && str_contains($item['exec_script'], '--c='))) {
                $cronForkTask->run_type = ScheduleEvent::RUN_TYPE;
            }else {
                // 其他语言类型的脚本
                $cronForkTask->run_type = '';
            }

            $newTaskList[] = $cronForkTask->toArray();
        }

        return $newTaskList;
    }
 }