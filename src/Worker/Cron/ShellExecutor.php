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
 * Shell 执行器（exec_type=1）。
 *
 * 边界：只消费 ExecutionSnapshot 冻结定义，不读最新 Runtime、不改 Timer。
 * CronForkProcess 可注入 $forkRunner，把 CronForkRunner / swoolefy script 接进来。
 * 无 forkRunner 时走本类内置 proc_open，记录子进程 PID（不是 Worker PID）。
 *
 * 命令拼装：有 exec_bin_file 则 bin + (exec_script ?: command) + argv；
 * 否则用 command ?: exec_script。空命令返回 FAILED，不抛异常。
 *
 * 失败隔离：proc_open 失败、非零退出、任意 Throwable 都返回 ExecutionResult::failed()，
 * 不得上抛到 CronManager / Worker（P0-5）。本类不重试，retry 由 CronManager 按
 * TaskDefinition::$retry 在同一 Snapshot 内编排。
 *
 * @see CompositeExecutor
 * @see CronForkProcess::executeCronSnapshot()
 */
final class ShellExecutor implements CronExecutorInterface
{
    /**
     * @param null|callable(ExecutionSnapshot):ExecutionResult $forkRunner CronForkProcess / CronForkRunner 执行钩子
     */
    public function __construct(private readonly mixed $forkRunner = null)
    {
    }

    /**
     * 执行冻结 Snapshot。有 forkRunner 则整段委托给 CronForkProcess::executeCronSnapshot()。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        try {
            if (is_callable($this->forkRunner)) {
                return ($this->forkRunner)($snapshot);
            }

            $command = $this->buildCommand($snapshot->definition);
            if ($command === '') {
                return ExecutionResult::failed('Shell command 为空');
            }

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            // Swoole 6：proc_open 必须在协程内。生产由 SwooleCronTimer goApp 保证。
            // 失败隔离：proc_open 失败只返回 FAILED，不抛到 Worker
            $process = @proc_open($command, $descriptors, $pipes);
            if (!is_resource($process)) {
                return ExecutionResult::failed('无法创建 Shell 进程: ' . $command);
            }

            $status = proc_get_status($process);
            $pid = (int) ($status['pid'] ?? 0);
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                stream_get_contents($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                stream_get_contents($pipes[2]);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $exitCode = proc_close($process);
            if ($exitCode === 0) {
                return ExecutionResult::success(
                    sprintf('Shell 执行成功 command=%s pid=%d', $command, $pid),
                    $pid,
                    0,
                );
            }

            return ExecutionResult::failed(
                sprintf('Shell 非零退出 command=%s pid=%d exit=%d', $command, $pid, $exitCode),
                $pid,
                $exitCode,
            );
        } catch (\Throwable $e) {
            return ExecutionResult::failed('Shell 执行异常: ' . $e->getMessage());
        }
    }

    /**
     * 拼装可交给 proc_open 的命令行。关联 argv 写成 --name=value，数字键只转义值。
     */
    private function buildCommand(TaskDefinition $definition): string
    {
        if ($definition->execBinFile !== '') {
            $script = $definition->execScript !== '' ? $definition->execScript : $definition->command;
            $argv = '';
            foreach ($definition->argv as $name => $value) {
                if (is_string($name)) {
                    $argv .= ' --' . $name . '=' . escapeshellarg((string) $value);
                } else {
                    $argv .= ' ' . escapeshellarg((string) $value);
                }
            }

            return trim($definition->execBinFile . ' ' . $script . $argv);
        }

        return trim($definition->command !== '' ? $definition->command : $definition->execScript);
    }
}
