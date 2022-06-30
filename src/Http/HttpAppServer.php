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

namespace Swoolefy\Http;

use Swoole\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Task\TaskController;

abstract class HttpAppServer extends \Swoolefy\Http\HttpServer
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
    abstract public function onWorkerStart($server, $worker_id);

    /**
     * onRequest
     * @param Request $request
     * @param Response $response
     * @return bool
     * @throws \Throwable
     */
    public function onRequest($request, $response)
    {
        if ($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            $response->end();
            return true;
        }
        try {
            $app_conf = \Swoolefy\Core\Swfy::getAppConf();
            $appInstance = new \Swoolefy\Core\App($app_conf);
            $appInstance->run($request, $response);
        } catch (\Throwable $throwable) {
            throw $throwable;
        }
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return void
     * @throws \Throwable
     */
    public function onTask($server, $task_id, $from_worker_id, $data, $task = null)
    {
        try {
            list($callable, $extendData, $fd) = $data;
            list($className, $action) = $callable;

            /**@var TaskController $taskInstance */
            $taskInstance = new $className;
            $taskInstance->setTaskId((int)$task_id);
            $taskInstance->setFromWorkerId((int)$from_worker_id);
            $task && $taskInstance->setTask($task);
            $taskInstance->$action($extendData);

            unset($callable, $extendData, $fd);

        } catch (\Throwable $throwable) {
            $taskInstance->end();
            throw $throwable;
        }
    }

    /**
     * onFinish
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return void
     */
    public function onFinish($server, $task_id, $data)
    {
    }

}	