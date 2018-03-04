<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class AppAsyncTask extends AsyncTask {
	/**
	 * 直接覆盖父级的registerTask()函数
     * registerTask 注册实例任务并调用异步任务，创建一个访问实例，相当于浏览器发出的一个请求，用于处理复杂业务
     * @param   string  $className
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($className, $data=[]) {
        if($className == '' || !is_string($className)) {
            _die(__NAMESPACE__.'::'.__METHOD__.' the param $className must be string');	
        }
        $className = trim($className,'/');
        if(is_string($data)) {
            $data = (array) $data;
        }    

        // 只有在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        if(self::isWorkerProcess()) {
            $task_id = Swfy::$server->task([$className, $data]);
            unset($className, $data);
            return $task_id;
        }
        return false;
    }
}