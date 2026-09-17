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

namespace Swoolefy\Worker\Config;

use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\ConfCtlStore;
use Swoolefy\Worker\Helper;

/**
 * Worker 配置加载器（无 EventLoop、无进程表）。
 *
 * 职责边界：
 * - include worker_daemon_conf.php / worker_cron_conf.php；
 * - 识别扁平列表 vs 分组配置，并按 CLI `--group=` 过滤；
 * - 与 confctl.json 原子合并：历史进程清理、`running=0` 的进程本次不启动。
 *
 * 调用方：`AbstractMainProcess::parseWorkerConf()`、`CtlApi`、CLI START 指定进程。
 * 多实例隔离不在这里做（PID 目录 / WORKER_SERVICE_NAME 由 Helper::makeServerName 处理），
 * 这里只保证「当前进程看到的 conf」已按本实例的 `--group` 收窄。
 */
final class WorkerConfLoader
{
    /**
     * 已解析的配置文件绝对路径。loadWorkerConf / useConfFile 写入，includeWorkerConf 读取。
     *
     * @var string|false|null
     */
    private static $confPath;

    /**
     * 启动入口：绑定 conf 路径并与 confctl 合并后返回待拉起的进程列表。
     *
     * 等价于 defaultLoadWorkerConf；保留此名是为了 MainManager 门面与历史调用点不变。
     *
     * @return list<array<string, mixed>>
     */
    public static function loadWorkerConf(string $confPath): array
    {
        return self::defaultLoadWorkerConf($confPath);
    }

    /**
     * include 当前 worker 配置文件。
     *
     * 技术点：
     * - 优先用已绑定的 `$confPath`，否则回退常量 `WORKER_CONF_FILE`；
     * - 顶层为分组（string key => list）时按 `--group` 展开，否则视为扁平进程列表；
     * - include 后立刻做 process_name 去重，重复名直接抛错，避免两组配了同名进程互相覆盖。
     *
     * @return list<array<string, mixed>>
     */
    public static function includeWorkerConf(): array
    {
        $fileConfPath = self::$confPath;
        if (empty($fileConfPath)) {
            $fileConfPath = WORKER_CONF_FILE;
        }
        $conf = include $fileConfPath;
        if (!is_array($conf)) {
            return [];
        }
        if (self::isGroupedWorkerConf($conf)) {
            $conf = self::resolveGroupedWorkerConf($conf);
        }
        self::findDuplicateProcessName($conf);

        return $conf;
    }

    /**
     * 判定是否为分组配置。
     *
     * 扁平：`[0 => ['process_name' => ...], 1 => ...]`（int 下标）。
     * 分组：`['group_1' => [...], 'group_2' => [...]]`（全部 string key 且值为 array）。
     * 空数组视为非分组，避免误把空 conf 当「零个组」。
     *
     * @param array<int|string, mixed> $conf
     */
    public static function isGroupedWorkerConf(array $conf): bool
    {
        if ($conf === []) {
            return false;
        }

        foreach ($conf as $key => $value) {
            if (is_int($key)) {
                return false;
            }
            if (!is_string($key) || !is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 按 CLI `--group=` 把分组 conf 展开成扁平进程列表。
     *
     * 规则（与 k8s 按组部署、本机多实例并存一致）：
     * - 未传 `--group`：合并全部组，便于开发一次拉起；
     * - `--group=group_1` 或 `--group=group_1,group_2`：只返回这些组；
     * - 指定了不存在的组名：抛 WorkerException，避免静默启动空实例。
     *
     * 分组名来自 `Helper::getCliParams('group')`（getenv），须在 daemon.php 写入 ENV 之后调用。
     *
     * @param array<string, array<int, array<string, mixed>>> $groupedConf
     *
     * @return list<array<string, mixed>>
     */
    public static function resolveGroupedWorkerConf(array $groupedConf): array
    {
        $groupParam = Helper::getCliParams('group');
        $groupNames = Helper::parseGroupNames(is_string($groupParam) ? $groupParam : null);

        if ($groupNames === []) {
            $items = [];
            foreach ($groupedConf as $groupName => $groupItems) {
                $items = array_merge($items, self::normalizeGroupProcessItems((string) $groupName, $groupItems));
            }

            return $items;
        }

        $available = implode(', ', array_keys($groupedConf));
        $items = [];
        foreach ($groupNames as $groupName) {
            if (!isset($groupedConf[$groupName]) || !is_array($groupedConf[$groupName])) {
                throw WorkerException::throw("未找到分组 [{$groupName}]，可选分组：{$available}");
            }
            $items = array_merge($items, self::normalizeGroupProcessItems($groupName, $groupedConf[$groupName]));
        }

        return $items;
    }

    /**
     * include 配置并与 confctl.json 合并，得到「本次真正要 start 的进程」。
     *
     * confctl 语义：
     * - 配置文件里已删除的进程：从 confctl 清掉，避免锁文件无限膨胀；
     * - 配置文件新增的进程：写入 running=1 / start_time；
     * - confctl 中 running=0：本次不启动（CtlApi / CLI 停过的进程保持停止）。
     *
     * `$store` 可注入，单测用临时文件，避免依赖 WORKER_PID_FILE_ROOT。
     * 生产路径走 ConfCtlStore::defaultPath()（优先 WORKER_CTL_CONF_FILE）。
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultLoadWorkerConf(string $confPath, ?ConfCtlStore $store = null): array
    {
        if (empty(self::$confPath)) {
            self::$confPath = realpath($confPath);
        }

        $currentProcessConfList = self::includeWorkerConf();
        $currentProcessConfListMap = array_column($currentProcessConfList, null, 'process_name');

        $store ??= new ConfCtlStore(ConfCtlStore::defaultPath());
        $fileProcessConfListMap = $store->update(function (array $fileProcessConfListMap) use ($currentProcessConfListMap) {
            foreach ($fileProcessConfListMap as $processName => $fileProcessConf) {
                if (!isset($currentProcessConfListMap[$processName])) {
                    unset($fileProcessConfListMap[$processName]);
                }
            }

            if (!empty($currentProcessConfListMap)) {
                foreach ($currentProcessConfListMap as $processName => $currentProcessConf) {
                    if (!isset($fileProcessConfListMap[$processName])) {
                        $fileProcessConfListMap[$processName] = [
                            'start_time' => date('Y-m-d H:i:s'),
                            'stop_time' => '',
                            'running' => 1,
                        ];
                    }
                }
            }

            return $fileProcessConfListMap;
        });

        foreach ($fileProcessConfListMap as $processName => $fileProcessConf) {
            if (isset($currentProcessConfListMap[$processName]) && ($fileProcessConf['running'] ?? 1) == 0) {
                unset($currentProcessConfListMap[$processName]);
            }
        }

        return array_values($currentProcessConfListMap);
    }

    /**
     * 检测扁平列表中重复的 process_name。
     *
     * 必须抛错而不能只构造异常：`WorkerException::throw()` 本身不 throw，
     * 调用方必须 `throw WorkerException::throw(...)`，否则重复名会静默并存，
     * 后续 md5(process_name) 作为 Registry key 会互相覆盖。
     *
     * @param list<array<string, mixed>> $conf
     */
    public static function findDuplicateProcessName(array &$conf): void
    {
        $processNames = array_column($conf, 'process_name');
        $uniqueProcessNames = array_unique($processNames);
        $duplicateProcessNames = array_diff_assoc($processNames, $uniqueProcessNames);
        if (!empty($duplicateProcessNames)) {
            $processNameStr = implode(',', $duplicateProcessNames);
            throw WorkerException::throw("conf配置项存在重复命名的进程[{$processNameStr}],请检查");
        }
    }

    /**
     * 当前绑定的配置文件路径（realpath 失败时可能为 false）。
     */
    public static function getConfPath(): string|false|null
    {
        return self::$confPath;
    }

    /**
     * 单测隔离：每个用例结束后清掉静态路径，避免污染后续 include。
     */
    public static function resetConfPath(): void
    {
        self::$confPath = null;
    }

    /**
     * 仅绑定配置文件路径，不读写 confctl。
     *
     * 给 CtlApi / CLI parseLoadConf / 单测用：它们只要「当前实例分组后的 conf」，
     * 不能走 defaultLoadWorkerConf，否则会改写 confctl 的 running 标记。
     */
    public static function useConfFile(string $confPath): void
    {
        $real = realpath($confPath);
        self::$confPath = $real !== false ? $real : $confPath;
    }

    /**
     * 将某一组的进程项规范成 list，并校验每项必须含 process_name。
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeGroupProcessItems(string $groupName, array $groupItems): array
    {
        $items = array_values($groupItems);
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['process_name'])) {
                throw WorkerException::throw("分组 [{$groupName}] 中存在无效的进程配置项，请检查 worker 配置文件");
            }
        }

        return $items;
    }
}
