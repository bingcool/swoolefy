<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Task\AsyncTaskInterface;

class AsyncTask implements AsyncTaskInterface {

    /**
     * registerTask 注册实例任务并调用异步任务，创建一个应用实例，用于处理复杂业务
     * @param   array  $callable
     * @param   array  $data
     * @throws  mixed
     * @return  int|boolean
     */
    public static function registerTask($callable, $data = []) {
        if(is_string($callable)) {
            throw new \Exception("AsyncTask::registerTask() function first params:callable must be an array", 1);
        }

        // 在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        if(!self::isWorkerProcess()) {
            throw new \Exception("AsyncTask::registerTask() Task Only Use In Worker Process!");
        }

        $callable[0] = str_replace('/', '\\', trim($callable[0],'/'));
        $fd = is_object(Application::getApp()) ? Application::getApp()->fd : null;
        // udp没有连接概念，存在client_info
        if(BaseServer::isUdpApp()) {
            $fd = is_object(Application::getApp()) ? Application::getApp()->client_info : null;
        }
        // http的fd其实没有实用意义
        if(BaseServer::isHttpApp()) {
            $fd = is_object(Application::getApp()) ? Application::getApp()->request->fd : null;
        }
        $task_id = Swfy::getServer()->task(serialize([$callable, $data, $fd]));
        unset($callable, $data, $fd);
        return $task_id;
    }

    /**
     * registerTaskfinish 异步任务完成并退出到worker进程
     * @param   mixed  $data
     * @param   mixed  $task
     * @return  void
     */
    public static function registerTaskfinish($data, $task = null) {
       static::finish($data, $task);
    }

    /**
     * finish registerTaskfinish函数-异步任务完成并退出到worker进程的别名函数
     * @param    mixed   $data
     * @param    mixed   $task
     * @return   void
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
     */
    public static function isWorkerProcess() {
       return Swfy::isWorkerProcess();
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return boolean
     */
    public static function isTaskProcess() {
        return Swfy::isTaskProcess();
    }
}