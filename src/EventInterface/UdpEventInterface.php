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

interface UdpEventInterface
{
    public function onWorkerStart(Server $server, int $worker_id);

    public function onPack(Server $server, $data, $clientInfo);

    public function onTask(Server $server, int $task_id, int $from_worker_id, $data);

    public function onFinish(Server $server, int $task_id, $data);
}