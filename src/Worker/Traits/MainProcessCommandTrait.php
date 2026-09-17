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

/**
 * 父进程 CLI 命令兼容门面。
 *
 * 实现已迁至 ProcessCommandHandler；Trait 只转发，避免历史代码 / 子类
 * 继续调 `$this->stopAllWorkerProcessCommand()` 时找不到方法。
 * 新增控制面逻辑请改 Handler，不要再往 Trait 加代码。
 */
trait MainProcessCommandTrait
{
    /**
     * 按当前实例 --group 过滤后的 conf 取指定进程；跨组返回 []。
     *
     * @param string $processName
     * @return array
     */
    protected function parseLoadConf(string $processName): array
    {
        return $this->getCommandHandler()->parseLoadConf($processName);
    }

    /**
     * 经 WORKER_TO_CLI_PIPE 回写终端。
     *
     * @param string $msg
     * @return mixed
     */
    protected function responseMsgByPipe(string $msg)
    {
        $this->getCommandHandler()->responseMsgByPipe($msg);
    }

    /**
     * 重启指定进程（发 REBOOT_FLAG，不杀 Master）。
     *
     * @param string $processName
     * @return void
     */
    protected function restartWorkerProcessCommand(string $processName)
    {
        $this->getCommandHandler()->restartWorkerProcessCommand($processName);
    }

    /**
     * 热启动一个静态进程（CLI start 指定进程）。
     *
     * @param array $config
     * @return void
     */
    protected function startWorkerProcessCommand(array $config)
    {
        $this->getCommandHandler()->startWorkerProcessCommand($config);
    }

    /**
     * 停止指定进程并从表删除。
     *
     * @param string $processName
     * @return void
     */
    protected function stopWorkerProcessCommand(string $processName)
    {
        $this->getCommandHandler()->stopWorkerProcessCommand($processName);
    }

    /**
     * 通知全部业务子进程退出（关机第 2 步）。
     *
     * @return void
     */
    protected function stopAllWorkerProcessCommand()
    {
        $this->getCommandHandler()->stopAllWorkerProcessCommand();
    }

    /**
     * 重启整个 Swoole Server，命令行带回 --group/--only。
     *
     * @return void
     */
    protected function restartServerCommand()
    {
        $this->getCommandHandler()->restartServerCommand();
    }
}
