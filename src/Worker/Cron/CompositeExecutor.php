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

namespace Swoolefy\Worker\Cron;

/**
 * 按 exec_type 分发到 Shell / HTTP 执行器。
 *
 * 不为了“现代化”增加 MQ / RPC 等更多类型；未知 exec_type 回退 Shell。
 * 本类不解析 URL、不拼命令，只做路由。CronProcess 默认用本类；
 * CronForkProcess / CronUrlProcess 会覆盖 createCronExecutor() 注入带执行钩子的单类型执行器。
 *
 * @see CronExecutorInterface
 * @see CronProcess::createCronExecutor()
 */
final class CompositeExecutor implements CronExecutorInterface
{
    public function __construct(
        private readonly CronExecutorInterface $shell,
        private readonly CronExecutorInterface $http,
    ) {
    }

    /**
     * EXEC_HTTP → HttpExecutor，其余（含 EXEC_SHELL）→ ShellExecutor。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        if ($snapshot->definition->execType === TaskDefinition::EXEC_HTTP) {
            return $this->http->run($snapshot);
        }

        return $this->shell->run($snapshot);
    }
}
