<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;

class AsyncTask implements AsyncTaskInterface {

    /**
     * registerTask 注册实例任务并调用异步任务，创建一个应用实例，用于处理复杂业务
     * @param  array $callable
     * @param  array $data
     * @throws mixed
     * @return int|boolean
     */
    public static function registerTask($callable, $data = []) {
        if(is_string($callable)) {
            throw new \Exception("AsyncTask::registerTask() function first argument of callable must be an array");
        }

        if(!self::isWorkerProcess()) {
            throw new \Exception("AsyncTask::registerTask() Task Only Use In Worker Process");
        }

        $callable[0] = str_replace('/', '\\', trim($callable[0],'/'));
        $fd = is_object(Application::getApp()) ? Application::getApp()->fd : null;
        if(BaseServer::isUdpApp()) {
            /**@var \Swoolefy\Udp\UdpHandler $app*/
            $app = Application::getApp();
            // udp没有连接概念,存在client_info
            if(is_object($app)) $fd = $app->getClientInfo();
        }

        if(BaseServer::isHttpApp()) {
            //http的fd其实没有实用意义
            $fd = is_object(Application::getApp()) ? Application::getApp()->request->fd : null;
        }
        $task_id = Swfy::getServer()->task(serialize([$callable, $data, $fd]));
        unset($callable, $data, $fd);
        return $task_id;
    }

    /**
     * registerTaskFinish 异步任务完成并退出到worker进程
     * @param  mixed $data
     * @param  mixed $task
     * @return void
     */
    public static function registerTaskFinish($data, $task = null) {
       static::finish($data, $task);
    }

    /**
     * finish registerTaskFinish函数-异步任务完成并退出到worker进程的别名函数
     * @param  mixed $data
     * @param  mixed $task
     * @return void
     */
    public static function finish($data, $task = null) {
        if(is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        if(BaseServer::isTaskEnableCoroutine() && $task instanceof \Swoole\Server\Task) {
            $task->finish($data);
        }else {
            Swfy::getServer()->finish($data);
        }
    }

    /**
     * getCurrentWorkerId 获取当前执行进程的id
     * @return int
     */
    public static function getCurrentWorkerId() {
        return Swfy::getServer()->worker_id;
    }

    /**
     * isWorkerProcess 判断当前进程是否是worker进程
     * @return boolean
     * @throws \Exception
     */
    public static function isWorkerProcess() {
       return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return boolean
     * @throws \Exception
     */
    public static function isTaskProcess() {
        return Swfy::isTaskProcess();
    }
}