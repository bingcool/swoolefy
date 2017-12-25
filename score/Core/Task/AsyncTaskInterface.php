<?php
namespace Swoolefy\Core\Task;

interface AsyncTaskInterface {
    /**
     * registerTask 注册并调用异步任务
     */
    public static function registerTask($route, $data);

    /** 
    * finish 异步任务完成并退出到worker进程,执行finish进程业务
    */
    public static function registerTaskfinish($callable, $data);
}