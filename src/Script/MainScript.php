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

namespace Swoolefy\Script;

use Swoolefy\Core\Swfy;
use Swoolefy\Worker\AbstractMainWorker;

abstract class MainScript extends AbstractMainWorker {

    /**
     * @return void
     */
    protected function exitAll(bool $force = false)
    {
        $swooleMasterPid = Swfy::getMasterPid();
        if(\Swoole\Process::kill($swooleMasterPid, 0)) {
            if($force) {
                \Swoole\Process::kill($swooleMasterPid, SIGKILL);
            }else {
                \Swoole\Process::kill($swooleMasterPid, SIGTERM);
            }
        }
    }
}