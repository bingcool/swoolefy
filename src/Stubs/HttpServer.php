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

namespace protocol\http;

use Swoole\Http\Server;
use Swoolefy\Core\Swfy;

class HttpServer extends \Swoolefy\Http\HttpAppServer
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
    public function onWorkerStart($server, $worker_id)
    {
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage($server, $from_worker_id, $message)
    {
    }

}	