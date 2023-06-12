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

use Swoole\Server;

interface RpcEventInterface
{
    public function onWorkerStart(Server $server, int $worker_id);

    public function onConnect(Server $server, int $fd);

    public function onReceive(Server $server, int $fd, $reactor_id, $data);

    public function onTask(Server $server, int $task_id, int $from_worker_id, $data);

    public function onFinish(Server $server, int $task_id, $data);

    public function onClose(Server $server, int $fd);
}