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

use Swoolefy\Core\Dto\TaskMessageDto;

class TaskManager
{

    use \Swoolefy\Core\SingletonTrait;

    /**
     * @param TaskMessageDto
     * @return mixed
     */
    public static function asyncTask(TaskMessageDto $taskMessageDto)
    {
        return AsyncTask::registerTask($taskMessageDto);
    }

    /**
     * finish 异步任务完成,消息发送worker
     * @param mixed $data
     * @param mixed $task
     * @return void
     */
    public static function finish($data = null, $task = null)
    {
        AsyncTask::finish($data, $task);
    }

    /**
     * registerTaskFinish
     * @param mixed $data
     * @param mixed $task
     * @return void
     */
    public static function registerTaskFinish($data = null, $task = null)
    {
        AsyncTask::registerTaskfinish($data, $task);
    }
}