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

namespace Swoolefy\Worker\Cron;

/**
 * RunOnce Execution INSERT 撞上 UNIQUE(request_id)。
 *
 * 这是预期竞争，不是写库故障。logWriter / {@see CronProcess::logCronTaskRuntime()}
 * / {@see CronManager::invokeLogWriter()} 必须原样上抛，由管线短路执行器。
 *
 * $gate：
 * - {@see CronRunOnceClaimConst::ACK}：已有终态，consume 侧补 ack，不执行
 * - {@see CronRunOnceClaimConst::DEFER}：已有 RUNNING 且租约有效，不 ack、不执行
 */
final class CronRunOnceAlreadyClaimedException extends \RuntimeException
{
    public function __construct(
        public readonly string $gate = CronRunOnceClaimConst::DEFER,
        string $message = 'run once execution already claimed',
    ) {
        parent::__construct($message);
    }

    public function shouldAck(): bool
    {
        return $this->gate === CronRunOnceClaimConst::ACK;
    }
}
