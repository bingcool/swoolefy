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

namespace protocol\udp;

use Swoole\Server;
use Swoolefy\Core\Swfy;

class UdpEventServer extends \Swoolefy\Udp\UdpEventServer
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
     * @return void
     */
    public function onWorkerStart(Server $server, int $worker_id)
    {
    }

    /**
     * onFinish
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return mixed
     */
    public function onFinish(Server $server, int $task_id, $data)
    {
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage(Server $server, int $from_worker_id, $message)
    {
    }

}
