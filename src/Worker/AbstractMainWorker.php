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

namespace Swoolefy\Worker;

use Swoolefy\Core\Process\AbstractProcess;

/**
 * swoole的自定义进程作为管理进程
 */
abstract class AbstractMainWorker extends AbstractProcess
{
    /**
     * @return void
     */
    public function init()
    {
        if(defined('WORKER_CONF')) {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $mainManager->loadConf(WORKER_CONF);
        }
    }
}