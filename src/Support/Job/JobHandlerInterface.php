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

namespace Swoolefy\Support\Job;

interface JobHandlerInterface
{
    /**
     * 本 Handler 声明处理的 jobType 列表。
     *
     * @return list<string>
     */
    public function types(): array;

    public function handle(JobEnvelope $job): JobResult;
}
