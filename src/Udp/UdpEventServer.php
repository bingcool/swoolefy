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

namespace Swoolefy\Udp;

include_once SWOOLEFY_CORE_ROOT_PATH . '/MainEventInterface.php';

use Swoole\Server;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\UdpEventInterface;

abstract class UdpEventServer extends UdpServer implements UdpEventInterface
{

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return mixed
     */
    abstract public function onWorkerStart($server, $worker_id);

    /**
     * onPack
     * @param Server $server
     * @param mixed $data
     * @param array $clientInfo
     * @return void
     * @throws \Throwable
     */
    public function onPack($server, $data, $clientInfo)
    {
        $appInstance = new UdpHandler(Swfy::getAppConf());
        $appInstance->run($data, $clientInfo);
    }

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return bool
     * @throws \Throwable
     */
    public function onTask($server, $task_id, $from_worker_id, $data, $task = null)
    {
        list($callable, $taskData, $clientInfo) = $data;
        $appInstance = new UdpHandler(Swfy::getAppConf());
        $appInstance->run([$callable, $taskData], $clientInfo, [$from_worker_id, $task_id, $task]);
        return true;
    }

    /**
     * @param $server
     * @param $task_id
     * @param $data
     * @return mixed
     */
    abstract public function onFinish($server, $task_id, $data);

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

}
