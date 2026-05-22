<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\Support;

use Swoolefy\Core\Exec;

/**
 * 按 Master PID 或进程名清理 Swoole HTTP 进程树（含 Manager、Worker、自定义进程）。
 */
final class ProcessTreeTerminator
{
    public static function terminateHttpServerTree(
        int $masterPid,
        string $masterProcessName,
        string $managerProcessName,
        int $graceSeconds = 5,
        int $maxWaitSeconds = 25,
    ): void {
        if ($masterPid > 0 && self::isAlive($masterPid)) {
            \Swoole\Process::kill($masterPid, SIGTERM);
        }

        $deadline = time() + $maxWaitSeconds;
        while (time() < $deadline) {
            if ($masterPid <= 0 || !self::isAlive($masterPid)) {
                break;
            }
            usleep(500_000);
        }

        if ($masterPid > 0 && self::isAlive($masterPid)) {
            self::killProcessTree($masterPid);
        }

        if ($graceSeconds > 0) {
            sleep($graceSeconds);
        }

        self::killByProcessTitle($masterProcessName);
        self::killByProcessTitle($managerProcessName);
        self::killByProcessTitle('php-swoolefy-http-worker');

        if ($masterPid > 0) {
            self::killProcessTree($masterPid);
        }
    }

    public static function killProcessTree(int $rootPid): void
    {
        if ($rootPid <= 0) {
            return;
        }

        $queue = [$rootPid];
        $visited = [];

        while ([] !== $queue) {
            $pid = array_shift($queue);
            if ($pid <= 0 || isset($visited[$pid])) {
                continue;
            }
            $visited[$pid] = true;

            foreach (self::childPids($pid) as $childPid) {
                $queue[] = $childPid;
            }
        }

        foreach (array_reverse(array_keys($visited)) as $pid) {
            if (self::isAlive($pid)) {
                \Swoole\Process::kill($pid, SIGKILL);
            }
        }
    }

    /**
     * @return int[]
     */
    public static function childPids(int $parentPid): array
    {
        $exec = (new Exec())->run('pgrep -P ' . $parentPid);
        $output = $exec->getOutput() ?? [];

        return array_values(array_filter(array_map('intval', $output)));
    }

    public static function killByProcessTitle(string $processTitle): void
    {
        if ('' === $processTitle) {
            return;
        }

        $pattern = escapeshellarg($processTitle);
        (new Exec())->run("pkill -f {$pattern} 2>/dev/null || true");
        usleep(200_000);
        (new Exec())->run("pkill -9 -f {$pattern} 2>/dev/null || true");
    }

    public static function isAlive(int $pid): bool
    {
        return $pid > 0 && \Swoole\Process::kill($pid, 0);
    }

    public static function killListenersOnPort(int $port): void
    {
        if ($port <= 0) {
            return;
        }

        $pids = (new Exec())->run(sprintf('lsof -ti tcp:%d 2>/dev/null', $port))->getOutput() ?? [];
        foreach ($pids as $line) {
            $listenerPid = (int) trim((string) $line);
            if ($listenerPid > 0 && self::isAlive($listenerPid)) {
                \Swoole\Process::kill($listenerPid, SIGKILL);
            }
        }
    }
}
