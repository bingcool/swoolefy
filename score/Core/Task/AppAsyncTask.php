<?php
namespace Swoolefy\Core\Task;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;

class AppAsyncTask extends AsyncTask {
	/**
	 * 直接覆盖父级的registerTask()函数
     * registerTask 注册实例任务并调用异步任务，创建一个访问实例，相当于浏览器发出的一个请求，用于处理复杂业务
     * @param   string  $route
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($route, $data=[]) {
        if($route == '' || !is_string($route)) {
            _die(__NAMESPACE__.'::'.__METHOD__.' the param $route must be string');	
        }
        $route = '/'.trim($route,'/');
        if(is_string($data)) {
            $data = (array) $data;
        }    


        // $request = swoole_pack(Application::$app->request);

        // $context = [$request, $response];
        // array_push($context, $route);
        // var_dump($request);

        // 只有在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        // if(self::isWorkerProcess()) {
        //     $task_id = Swfy::$server->task([$context, $data]);
        //     unset($context);
        //     return $task_id;
        // }
        return false;
    }
}