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

/**
 * rpc
 */
interface RpcEventInterface
{
    public function onWorkerStart($server, $worker_id);

    public function onConnect($server, $fd);

    public function onReceive($server, $fd, $reactor_id, $data);

    public function onTask($server, $task_id, $from_worker_id, $data);

    public function onFinish($server, $task_id, $data);

    public function onClose($server, $fd);
}

/**
 * websocket
 */
interface WebsocketEventInterface
{
    public function onWorkerStart($server, $worker_id);

    public function onOpen($server, $request);

    public function onRequest($request, $response);

    public function onMessage($server, $frame);

    public function onTask($server, $task_id, $from_worker_id, $data);

    public function onFinish($server, $task_id, $data);

    public function onClose($server, $fd);
}

/**
 * udp
 */
interface UdpEventInterface
{
    public function onWorkerStart($server, $worker_id);

    public function onPack($server, $data, $clientInfo);

    public function onTask($server, $task_id, $from_worker_id, $data);

    public function onFinish($server, $task_id, $data);
}