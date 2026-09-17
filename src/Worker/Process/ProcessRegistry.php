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

namespace Swoolefy\Worker\Process;

/**
 * Master 进程表：可变状态的唯一存放处。
 *
 * 表结构（key 一律 `md5($processName)`，与历史 MainManager 一致，拆分阶段不得改 hash 算法）：
 * - `$processLists`：静态配置（worker_num、max_process_num、dynamic_process_*）；
 * - `$processWorkers`：已 fork 的 AbstractBaseWorker 实例，二维 [key][workerId]；
 * - `$stoppingDynamicProcesses`：已发退出信号、等待 SIGCHLD 的动态进程 pid => processName；
 * - `$processStatusList`：子进程上报的 runtime（内存等），按原始 processName 索引而非 md5。
 *
 * 写入约定：只有 ProcessSupervisor 增删 worker / 改 lists；
 * CliPipe / StatusReporter / CtlApi 只读。禁止在业务里再复制一份表。
 */
final class ProcessRegistry
{
    /**
     * 进程配置表。key = md5(process_name)。
     *
     * @var array<string, array<string, mixed>>
     */
    private array $processLists = [];

    /**
     * 存活 worker 实例表。key = md5(process_name)，内层 key 为 workerId。
     *
     * @var array<string, array<int, object>>
     */
    private array $processWorkers = [];

    /**
     * 已发出停止请求、等待 SIGCHLD 回收的动态进程。
     * 发信号时只标记、不减计数；reap 后再 storageDynamicProcessNum。
     *
     * @var array<int, string>
     */
    private array $stoppingDynamicProcesses = [];

    /**
     * 预留 PID 映射（历史字段，查询请用 findByPid 遍历 workers）。
     *
     * @var array<string, mixed>
     */
    private array $processPidMap = [];

    /**
     * 子进程 pipe 上报的 runtime 状态，供 StatusReporter 展示。
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $processStatusList = [];

    /**
     * 进程名到表 key。保持 md5，与 FIFO / 动态进程历史数据兼容。
     */
    public static function key(string $processName): string
    {
        return md5($processName);
    }

    /**
     * 全部进程配置（含尚未 fork 或已 stop 仍留在 lists 里的项）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function allLists(): array
    {
        return $this->processLists;
    }

    /**
     * 配置表是否已登记该 process_name（CLI START 用来判断「已存在请用 restart」）。
     */
    public function hasList(string $processName): bool
    {
        return isset($this->processLists[self::key($processName)]);
    }

    /**
     * 取一份进程配置；不存在返回空数组而不是 null，方便扩缩容读字段。
     *
     * @return array<string, mixed>
     */
    public function getList(string $processName): array
    {
        return $this->processLists[self::key($processName)] ?? [];
    }

    /**
     * @param array<string, mixed> $list
     */
    public function setList(string $processName, array $list): void
    {
        $this->processLists[self::key($processName)] = $list;
    }

    /**
     * CLI 停止指定进程后从配置表移除，避免 start() 再次认为该进程仍在运行清单中。
     */
    public function unsetList(string $processName): void
    {
        unset($this->processLists[self::key($processName)]);
    }

    /**
     * 单测注入整表。生产路径应走 setList，避免绕过校验。
     *
     * @param array<string, array<string, mixed>> $lists
     */
    public function replaceLists(array $lists): void
    {
        $this->processLists = $lists;
    }

    /**
     * @return array<string, array<int, object>>
     */
    public function allWorkers(): array
    {
        return $this->processWorkers;
    }

    /**
     * 该进程名下是否还有任意 worker（含动态）。CLI restart/stop 用。
     */
    public function hasWorkerGroup(string $processName): bool
    {
        return isset($this->processWorkers[self::key($processName)]);
    }

    public function hasWorker(string $processName, int $workerId): bool
    {
        return isset($this->processWorkers[self::key($processName)][$workerId]);
    }

    public function getWorker(string $processName, int $workerId): ?object
    {
        return $this->processWorkers[self::key($processName)][$workerId] ?? null;
    }

    /**
     * 取该进程名下全部 worker。
     *
     * 与历史 `$this->processWorkers[$key]` 对齐：key 不存在返回 null（不是 []），
     * 以便 getProcessByName($name, -1) 在缺失时保持原语义。
     *
     * @return array<int, object>|null
     */
    public function getWorkersByName(string $processName): ?array
    {
        $key = self::key($processName);
        if (!array_key_exists($key, $this->processWorkers)) {
            return null;
        }

        return $this->processWorkers[$key];
    }

    public function setWorker(string $processName, int $workerId, object $process): void
    {
        $this->processWorkers[self::key($processName)][$workerId] = $process;
    }

    /**
     * 从实例表删除一个 worker；该组清空时连带删掉外层 key，避免空数组残留导致 hasWorkerGroup 为 true。
     */
    public function removeWorker(string $processName, int $workerId): void
    {
        $key = self::key($processName);
        unset($this->processWorkers[$key][$workerId]);
        if (isset($this->processWorkers[$key]) && $this->processWorkers[$key] === []) {
            unset($this->processWorkers[$key]);
        }
    }

    /**
     * @param array<string, array<int, object>> $workers
     */
    public function replaceWorkers(array $workers): void
    {
        $this->processWorkers = $workers;
    }

    /**
     * SIGCHLD / reboot 按 pid 反查实例。无独立 pid map，遍历 workers（Master 进程数有上限）。
     */
    public function findByPid(int $pid): ?object
    {
        foreach ($this->processWorkers as $processes) {
            foreach ($processes as $process) {
                if (!is_object($process) || !method_exists($process, 'getPid')) {
                    continue;
                }
                if ((int) $process->getPid() === $pid) {
                    return $process;
                }
            }
        }

        return null;
    }

    public function countWorkers(): int
    {
        $n = 0;
        foreach ($this->processWorkers as $processes) {
            $n += count($processes);
        }

        return $n;
    }

    /**
     * @return array<int, string>
     */
    public function stoppingDynamicProcesses(): array
    {
        return $this->stoppingDynamicProcesses;
    }

    /**
     * 缩容：发退出信号前登记 pid，后续重复 destroy 同一 pid 幂等跳过。
     */
    public function markStoppingDynamic(int $pid, string $processName): void
    {
        $this->stoppingDynamicProcesses[$pid] = $processName;
    }

    /**
     * SIGCHLD reap 动态进程后解除 stopping 标记，再重算 dynamic_process_worker_num。
     */
    public function unmarkStoppingDynamic(int $pid): void
    {
        unset($this->stoppingDynamicProcesses[$pid]);
    }

    public function isStoppingDynamicPid(int $pid): bool
    {
        return isset($this->stoppingDynamicProcesses[$pid]);
    }

    /**
     * 该进程名是否仍有动态进程在 stopping 集合。用于刷新 dynamic_process_destroying。
     */
    public function hasStoppingDynamicProcess(string $processName): bool
    {
        return in_array($processName, $this->stoppingDynamicProcesses, true);
    }

    /**
     * @param array<int, string> $map
     */
    public function replaceStoppingDynamic(array $map): void
    {
        $this->stoppingDynamicProcesses = $map;
    }

    /**
     * @param array<string, mixed> $status
     */
    public function setRuntimeStatus(string $processName, int $workerId, array $status): void
    {
        $this->processStatusList[$processName][$workerId] = $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeStatus(string $processName, int $workerId): array
    {
        return $this->processStatusList[$processName][$workerId] ?? [];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function allRuntimeStatus(): array
    {
        return $this->processStatusList;
    }

    /**
     * @return array<string, mixed>
     */
    public function allPidMap(): array
    {
        return $this->processPidMap;
    }
}
