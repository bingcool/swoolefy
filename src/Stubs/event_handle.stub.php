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

namespace protocol\event;

use Swoolefy\Core\EventHandler;
use Swoolefy\Core\SystemEnv;

class Event extends EventHandler
{
    /**
     * onInit
     */
    public function onInit() {

        if (!SystemEnv::isWorkerService()) {
            // todo refer to Test Demo
        }
    }

    /**
     * onWorkerServiceInit
     */
    public function onWorkerServiceInit()
    {
        // todo refer to Test Demo
    }

    /**
     * onWorkerStart
     * @param $server
     * @param $worker_id
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {
        if (!SystemEnv::isWorkerService()) {
            // todo
        }
    }

    /**
     * @param $server
     * @param $worker_id
     * @return void
     */
    public function onWorkerStop($server, $worker_id)
    {
        // todo
    }
}