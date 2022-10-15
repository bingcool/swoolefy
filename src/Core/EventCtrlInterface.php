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

namespace Swoolefy\Core;

use Swoole\Server;

interface EventCtrlInterface
{
    /**
     * init
     * @return void
     */
    public function init();

    /**
     * onStart
     * @param Server $server
     * @return void
     */
    public function start($server);

    /**
     * onManagerStart
     * @param Server $server
     * @return void
     */
    public function managerStart($server);

    /**
     * onWorkerStart
     * @param Server $server
     * @return void
     */
    public function workerStart($server, $worker_id);

    /**
     * onWorkerStop
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    public function workerStop($server, $worker_id);

    /**
     * workerError
     * @param Server $server
     * @param int $worker_id
     * @param int $worker_pid
     * @param int $exit_code
     * @param int $signal
     * @return void
     */
    public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal);

    /**
     * workerExit
     * @param Server $server
     * @param $worker_id
     * @return void
     */
    public function workerExit($server, $worker_id);

    /**
     * onManagerStop
     * @param Server $server
     * @return void
     */
    public function managerStop($server);
}