<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Core\Task;

interface AsyncTaskInterface
{
    /**
     * registerTask 注册并调用异步任务
     * @param $route
     * @param $data
     * @return mixed
     */
    public static function registerTask($route, array $data);

    /**
     * finish 异步任务完成并退出到worker进程,执行finish进程业务
     * @param $data
     * @return mixed
     */
    public static function registerTaskFinish($data);
}