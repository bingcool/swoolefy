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

abstract class AbstractMainWorker extends AbstractProcess {

    /**
     * @return array
     */
    protected function setWorkerMasterPid()
    {
        defined('WORKER_MASTER_PID') or define('WORKER_MASTER_PID', $this->getPid());
    }
}