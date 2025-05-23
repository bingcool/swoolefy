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

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Task\TaskController;

abstract class HttpAppServer extends HttpServer
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
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onRequest
     * @param Request $request
     * @param Response $response
     * @return bool
     * @throws \Throwable
     */
    public function onRequest(Request $request, Response $response)
    {
        $appInstance = new \Swoolefy\Core\App(Swfy::getAppConf());
        $appInstance->run($request, $response);
        return true;
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage(Server $server, int $from_worker_id, $message);

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
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        try {
            list($callable, $taskData, $contextData, $fd) = $data;
            list($className, $action) = $callable;

            /**@var TaskController $taskInstance */
            $taskInstance = new $className;
            $taskInstance->setTaskId((int)$task_id);
            $taskInstance->setFromWorkerId((int)$from_worker_id);
            $task && $taskInstance->setTask($task);
            foreach ($contextData as $key => $value) {
                \Swoolefy\Core\Coroutine\Context::set($key, $value);
            }
            $taskInstance->$action($taskData);
            $taskInstance->afterHandle();

            unset($callable, $extendData, $fd);

        } catch (\Throwable $throwable) {
            if(!$taskInstance->isDefer()) {
                $taskInstance->end();
            }
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
    public function onFinish(Server $server, int $task_id, $data)
    {
    }

}	