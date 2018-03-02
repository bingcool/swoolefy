<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class AsyncTask {

    /**
     * registerTask 注册实例任务并调用异步任务，创建一个访问实例，相当于浏览器发出的一个请求，用于处理复杂业务
     * @param   string  $route
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($route, $data=[]) {

    }

    /**
     * registerStaticCallTask 注册静态类调用形式任务，不用创建实例，用于处理简单业务或者日志发送等
     * @param    array   $class
     * @param    array   $data
     * @return    int|boolean
     */
    public static function registerStaticCallTask($class, $data=[]) {
        if(!is_array($class) || !is_array($data)) {
            _die(__NAMESPACE__.'::'.__METHOD__.' the param $class and $data must be array');	
        }
        if(count($class) != 2) {
            _die(__NAMESPACE__.'::'.__METHOD__.' the param $class only need to 2 elements');
        }
        if(is_string($data)) {
            $data = (array) $data;
        }
        $class[0] = str_replace('/','\\', $class[0]);
        $class[0] = trim($class[0], '\\'); 
        if(self::isWorkerProcess()) {
            $task_id = Swfy::$server->task([$class, $data]);
            unset($class, $data);
            return $task_id;
        }
        return false;
    }

    /**
     * finish 异步任务完成并退出到worker进程
     * @param   mixed  $data
     * @return    void
     */
    public static function registerTaskfinish($callable, $data) {
        return Swfy::$server->finish([$callable, $data]);
    }

    /**
     * getCurrentWorkerId 获取当前执行进程的id
     * @return int
     */
    public static function getCurrentWorkerId() {
        return Swfy::$server->worker_id;
    }

    /**
     * isWorkerProcess 判断当前进程是否是worker进程
     * @return boolean
     */
    public static function isWorkerProcess() {
        $worker_id = self::getCurrentWorkerId();
        $max_worker_id = (Swfy::$config['setting']['worker_num']) - 1;
        return ($worker_id <= $max_worker_id) ? true : false;
    }

    /**
     * isTaskProcess 判断当前进程是否是异步task进程
     * @return boolean
     */
    public static function isTaskProcess() {
        return (self::isWorkerProcess()) ? false : true;
    }
}