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
use Swoolefy\Core\Log\LogManager;

class EventHandler extends \Swoolefy\Core\EventCtrl
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @return void
     */
    public function onInit()
    {
        // default register logger
        $app_conf = Swfy::getAppConf();
        if (isset($app_conf['components']['log'])) {
            $log = $app_conf['components']['log'];
            if ($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'log');
            }
        }

        if (isset($app_conf['components']['error_log'])) {
            $log = $app_conf['components']['error_log'];
            if ($log instanceof \Closure) {
                LogManager::getInstance()->registerLoggerByClosure($log, 'error_log');
            }
        }
    }

    /**
     * WorkerServiceInit
     */
    public function onWorkerServiceInit() {}

    /**
     * onStart
     * @param Server $server
     * @return void
     */
    public function onStart($server)
    {

    }

    /**
     * onManagerStart
     * @param Server $server
     * @return void
     */
    public function onManagerStart($server)
    {

    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    public function onWorkerStart($server, $worker_id)
    {

    }

    /**
     * onWorkerStop
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    public function onWorkerStop($server, $worker_id)
    {

    }

    /**
     * workerError
     * @param  Server $server
     * @param  int $worker_id
     * @param  int $worker_pid
     * @param  int $exit_code
     * @param  int $signal
     * @return void
     */
    public function onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal)
    {

    }

    /**
     * workerExit
     * @param  Server $server
     * @param  int $worker_id
     * @return void
     */
    public function onWorkerExit($server, $worker_id)
    {

    }

    /**
     * onManagerStop
     * @param  Server $server
     * @return void
     */
    public function onManagerStop($server)
    {

    }
}