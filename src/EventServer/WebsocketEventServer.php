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

use Swoolefy\Core\Swfy;

class WebsocketEventServer extends \Swoolefy\Websocket\WebsocketEventServer
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
     * @param object $server
     * @param int $worker_id
     * @return   void
     */
    public function onWorkerStart($server, $worker_id)
    {
    }

    /**
     * onOpen
     * @param object $server
     * @param object $request
     * @return void
     */
    public function onOpen($server, $request)
    {
    }

    /**
     * onFinish
     * @param object $server
     * @param int $task_id
     * @param mixed $data
     * @return mixed
     */
    public function onFinish($server, $task_id, $data)
    {
    }

    /**
     * onPipeMessage
     * @param object $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage($server, $from_worker_id, $message)
    {
    }

    /**
     * onClose 连接断开处理
     * @param object $server
     * @param int $fd
     * @return void
     */
    public function onClose($server, $fd)
    {
    }

    /**
     * onMessageFromBinary 处理二进制数据
     * @param object $server
     * @param mixed $frame
     * @return void
     */
    public function onMessageFromBinary($server, $frame)
    {
    }

    /**
     * onMessageFromClose 处理关闭帧
     * Swoole 4.2.0+支持，需要开启设置"open_websocket_close_frame" => true，开启，默认false
     * @param object $server
     * @param mixed $frame
     * @return void
     */
    public function onMessageFromClose($server, $frame)
    {
    }

}
