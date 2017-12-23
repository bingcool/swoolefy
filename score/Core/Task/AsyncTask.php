<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;

class AsyncTask {

    /**
     * registerTask 注册并调用异步任务
     * @param   string  $route
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($route, $data) {
        if($route == '' || !is_string($route)) {
            throw new \Exception(__NAMESPACE__.'::'.__METHOD__.' the param $route must be string');	
        }
        $route = '/'.trim($route,'/');
        array_push($data, $route);

        dump(self::isWorkerProcess());
        // 只有在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        if(self::isWorkerProcess()) {
            $task_id = Swfy::$server->task($data);
            return $task_id;
        }
        return false;
    }

    /**
     * finish 异步任务完成并退出到worker进程
     * @param   mixed  $data
     * @return    void
     */
    public static function finish($data) {
        Swfy::$server->finish($data);
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
        dump($worker_id);
        $max_worker_id = (Swfy::$config['setting']['worker_num']) - 1;
        dump(Swfy::$config);
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