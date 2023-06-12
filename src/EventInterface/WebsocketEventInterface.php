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

namespace Swoolefy\EventInterface;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoole\Http\Request;
use Swoole\Http\Response;

interface WebsocketEventInterface
{
    public function onWorkerStart(Server$server, int $worker_id);

    public function onOpen(Server $server, Request $request);

    public function onRequest(Request $request, Response $response);

    public function onMessage(Server $server, Frame $frame);

    public function onTask(Server $server, int $task_id, int $from_worker_id, $data);

    public function onFinish(Server $server, int $task_id, $data);

    public function onClose(Server $server, int $fd);
}