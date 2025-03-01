<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core\Schedule;

use Swoolefy\Script\AbstractKernel;

class DynamicCallFn {

    /**
     * 调度任务动态创建唯一名称的pidFile
     *
     * @param ScheduleEvent $scheduleTask
     * @return void
     */
    public function generatePidFile(ScheduleEvent $scheduleTask)
    {
        $fileName = md5($scheduleTask->cron_name.time()).'.pid';
        $pidFile  = AbstractKernel::getCronScriptFullPidFile($fileName);
        $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();
        $scheduleTask->argv[$cronScriptPidFileOption]   = $pidFile;
        $scheduleTask->extend[$cronScriptPidFileOption] = $pidFile;
    }
}