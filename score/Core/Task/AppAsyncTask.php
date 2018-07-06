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
use Swoolefy\Core\Collection;

class AppAsyncTask extends AsyncTask {
	/**
	 * 直接覆盖父级的registerTask()函数
     * registerTask http协议服务，注册实例任务并调用异步任务，用于处理复杂长时间业务
     * @param   string  $className
     * @param   array   $data
     * @return    int|boolean
     */
    public static function registerTask($callable, $data = []) {
        if(is_string($callable)) {
            throw new \Exception("$callable must be array", 1);
        }
        $callable[0] = str_replace('/', '\\', trim($callable[0],'/'));
        $request = new Collection(Application::getApp()->request);
        $requestItems = $request->all();
        // 只有在worker进程中可以调用异步任务进程，异步任务进程中不能调用异步进程
        if(self::isWorkerProcess()) {
            $task_id = Swfy::getServer()->task(swoole_pack([$callable, $data, $requestItems]));
            unset($callable, $data, $requestItems);
            return $task_id;
        }
        return false;
    }
}