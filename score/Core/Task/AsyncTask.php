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
     * @param   string  $route
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($callable, $data = []) {
        if(is_string($callable)) {
            throw new \Exception("$callable must be an array", 1);
        }
        $callable[0] = str_replace('/', '\\', trim($callable[0],'/'));
        $fd = Application::getApp()->fd;
        // 只有在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        if(self::isWorkerProcess()) {
            // udp没有连接概念，存在client_info
            if(BaseServer::getServiceProtocol() == SWOOLEFY_UDP) {
                $fd = Application::getApp()->client_info;
            }

            $task_id = Swfy::getServer()->task(swoole_pack([$callable, $data, $fd]));
            unset($callable, $data, $fd);
            return $task_id;
        }
        return false;
    }

    /**
     * finish 异步任务完成并退出到worker进程
     * @param   mixed  $data
     * @return    void
     */
    public static function registerTaskfinish($data) {
       return static::finish($data);
    }

    /**
     * finish registerTaskfinish函数-异步任务完成并退出到worker进程的别名函数
     * @param    mixed   $callable
     * @param    mixed   $data
     * @return   void
     */
    public static function finish($data) {
        if(is_array($data)) {
            $data = json_encode($data);
        }
        return Swfy::getServer()->finish($data);
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
        return (!self::isWorkerProcess()) ? true : false;
    }
}