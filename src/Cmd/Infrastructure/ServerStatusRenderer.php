<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Infrastructure;

use Swoolefy\Cmd\Support\ProcessTreeTerminator;
use Swoolefy\Core\SystemEnv;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * 服务进程状态表格渲染器。
 *
 * 从 master PID 出发，通过 pgrep 遍历进程树，
 * 以表格形式展示 master / manager / worker 进程的状态。
 */
final class ServerStatusRenderer
{
    /**
     * 渲染服务进程状态表格。
     *
     * 若 PID 文件不存在或进程已退出，输出错误提示并返回。
     * 否则依次获取 master → manager → worker 进程 ID，以表格输出。
     *
     * @param string $appName 应用名称（用于日志输出）
     * @param string $pidFile PID 文件路径
     */
    public static function render(string $appName, string $pidFile): void
    {
        // PID 文件不存在 → 服务可能未启动
        if (!is_file($pidFile)) {
            fmtPrintError("PID file={$pidFile} does not exist, server may not be running");
            return;
        }

        $pid = (int) file_get_contents($pidFile);

        // PID 存在但进程已退出
        if (!ProcessTreeTerminator::isAlive($pid)) {
            fmtPrintError("Server may have shutdown, use 'ps -ef | grep php-swoolefy' to check");
            return;
        }

        // 打印框架 banner
        SystemEnv::formatPrintStartLog();

        // 通过 pgrep 获取子进程树
        $managerPid = -1;
        $workerPids = [];
        $children = ProcessTreeTerminator::childPids($pid);
        if (!empty($children)) {
            // 第一个子进程为 manager
            $managerPid = $children[0];
            // manager 的子进程为 worker
            $workerPids = ProcessTreeTerminator::childPids($managerPid);
        }

        // 构建并渲染表格
        $output = new ConsoleOutput();
        $table = new Table($output);
        $table->setHeaders(['进程名称', '进程ID', '父进程ID', '进程状态', '启动时间']);

        $rows = [
            ['master process', $pid, '--', 'running', '--'],
            ['manager process', $managerPid, $pid, 'running', '--'],
        ];

        foreach ($workerPids as $idx => $workerPid) {
            $rows[] = ["worker process-{$idx}", $workerPid, $managerPid, 'running', '--'];
        }

        $table->setRows($rows);

        // 使用 <info> 标签高亮行
        $tableStyle = new TableStyle();
        $tableStyle->setCellRowFormat('<info>%s</info>');
        $table->setStyle($tableStyle)->render();
    }
}
