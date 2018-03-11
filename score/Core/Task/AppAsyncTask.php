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
    public static function registerTask($callable, $data=[]) {
        
    }
}