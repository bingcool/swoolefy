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

namespace protocol\websocket;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;

class WebsocketEventServer extends \Swoolefy\Websocket\WebsocketEventServer
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
     * @return   void
     */
    public function onWorkerStart(Server $server, int $worker_id)
    {
    }

    /**
     * onOpen
     * @param Server $server
     * @param Request $request
     * @return void
     */
    public function onOpen(Server $server, Request $request)
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

    /**
     * onClose
     * @param Server $server
     * @param int $fd
     * @return void
     */
    public function onClose(Server $server, int $fd)
    {
    }

    /**
     * onMessageFromBinary
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    public function onMessageFromBinary(Server $server, Frame $frame)
    {
    }

    /**
     * onMessageFromClose
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    public function onMessageFromClose(Server $server, Frame $frame)
    {
    }

}
