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

namespace Swoolefy\Worker\Traits;

use Swoolefy\Worker\MainManager;

trait ChildrenCommandTrait {
    /**
     * 系统内置管道通信命令
     *
     * @var array
     */
    protected $systemCommandHandle = [
        'run-once-cron' => [self::class, 'runOnceCronCommand'],
    ];

    /**
     * 自定义管道通信命令
     *
     * @var array
     */
    protected $customCommandHandle = [];

    /**
     * 手动执行一次cron脚本
     * @param $msg
     * @param string $from_process_name
     * @param int $from_process_worker_id
     * @param bool $is_proxy_by_master
     * @return void
     */
    protected function runOnceCronCommand($msg, string $from_process_name, int $from_process_worker_id, bool $is_proxy_by_master)
    {
        putenv(self::RUN_ONCE_CRON."=1");
    }
}

