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
     * @param $item
     * @return void
     */
    public function generatePidFile(&$item)
    {
        $fileName = md5($item['cron_name'].time()).'.pid';
        $pidFile = AbstractKernel::getCronScriptFullPidFile($fileName);
        $cronScriptPidFile = AbstractKernel::OPTION_SCHEDULE_CRON_SCRIPT_PID_FILE;
        $item['argv'][] = "{$cronScriptPidFile}='{$pidFile}'";
        $cronScriptPidFileOption = AbstractKernel::getCronScriptPidFileOptionField();
        $item['extend'][$cronScriptPidFileOption] = $pidFile;
    }
}