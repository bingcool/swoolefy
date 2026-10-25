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

declare(strict_types=1);

namespace Swoolefy\Worker\Traits;

/**
 * worker process 接收指令处理
 */
trait WorkerProcessCommandTrait {
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
     * @param string $fromProcessName
     * @param int $fromProcessWorkeId
     * @param bool $isProxyByMaster
     * @return void
     */

    protected function runOnceCronCommand($msg, string $fromProcessName, int $fromProcessWorkeId, bool $isProxyByMaster)
    {
        putenv("run-once-cron=1");
    }
}

