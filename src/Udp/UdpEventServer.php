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

use Swoole\Server;
use Swoolefy\Core\Swfy;
use Swoolefy\EventInterface\UdpEventInterface;

abstract class UdpEventServer extends UdpServer implements UdpEventInterface
{

    /**
     * __construct
     * @param array $config
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
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onPack
     * @param Server $server
     * @param mixed $data
     * @param array $clientInfo
     * @return void
     * @throws \Throwable
     */
    public function onPack(Server $server, $data, $clientInfo)
    {
        $appInstance = new UdpHandler(Swfy::getAppConf());
        $appInstance->setClientInfo($clientInfo);
        $appInstance->run(null, $data);
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
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        list($callable, $taskData, $clientInfo) = $data;
        $appInstance = new UdpHandler(Swfy::getAppConf());
        $appInstance->setClientInfo($clientInfo);
        $appInstance->run(null, [$callable, $taskData], [$from_worker_id, $task_id, $task]);
        return true;
    }

    /**
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return mixed
     */
    abstract public function onFinish(Server $server, int $task_id, $data);

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage(Server $server, int $from_worker_id, $message);

}
