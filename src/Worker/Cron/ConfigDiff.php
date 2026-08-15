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
 * DB Snapshot 与 Runtime Snapshot 的最小化 Diff。
 *
 * 本类是纯比较器：不访问 DB、不改 Timer、不改 RuntimeJobRegistry。
 * CronManager::applyRows() 将 fetcher 行规范化为 TaskDefinition 后，
 * 以 jobId 为键构成 $desired，再与 registry->definitions() 的 $runtime 比较。
 *
 * 支持的 op：
 * - ADD：desired 有、runtime 无 → 新建 RuntimeJob；仅 STATUS_ENABLED 时武装 Timer
 * - UPDATE：同一 jobId 的调度元数据 fingerprint 变化 → 清旧 Timer、换定义、再武装
 * - DELETE：runtime 有、desired 无 → 停止未来调度；进行中的 Execution 不杀
 * - ENABLE：status 从 STATUS_DISABLED(0) 变为 STATUS_ENABLED(1) → 恢复调度
 * - DISABLE：status 从 STATUS_ENABLED(1) 变为 STATUS_DISABLED(0) → 清 Timer、保留 Job
 * - NOOP：身份、status、fingerprint 均未变 → CronManager::applyOp() 忽略
 *
 * 禁止“清空后全量重注册”（clear all + re-register），以免 Timer 重建、
 * 执行竞态与重复执行（架构文档 #9 Config Diff / P0-1）。
 *
 * 身份键（identity）：
 * 比较只认数组键 jobId，不在此重算身份。jobId 由 TaskDefinition::resolveJobId() 生成：
 * 优先 cron_task_id / 数值 id → "id:{n}"，否则 cron_name / name → "name:{name}"。
 * 无稳定 id 时改名会表现为旧键 DELETE + 新键 ADD。
 * nodeId 过滤发生在 CronManager 进入本方法之前：任务改挂其它节点会从 $desired
 * 消失，从而被分类为 DELETE。
 *
 * 字段比较：
 * - status 单独比较，走 ENABLE / DISABLE，不计入 TaskDefinition::fingerprint()
 * - fingerprint() 覆盖 expression、execType、withBlockLapping、command、
 *   cronBetween、cronSkip、httpMethod、httpBody、httpHeaders、httpRequestTimeOut、
 *   execBinFile、execScript、url、runType、forkType、argv、updatedAt、timezone
 * - cronName、cronTaskId、nodeId、output、extend、cronDbLogClass、cronMetaOrigin、raw
 *   不参与 fingerprint，单独变化不会产生 UPDATE
 * - 仅识别 STATUS_DISABLED ↔ STATUS_ENABLED；其它 status 取值不单独成 ENABLE/DISABLE
 *
 * ENABLE/DISABLE vs DELETE：
 * - DISABLE：desired 仍含该 jobId 且 status=0，RuntimeJob 保留，便于再次 ENABLE
 * - DELETE：desired 缺该 jobId（软删后不再出现、改节点、或成功拉取到空配置），
 *   标记 deleted，未 running 时从 Registry 移除
 *
 * Last Known Good：
 * 本类无法区分“DB 故障”与“配置确实为空”。空 $desired + 非空 $runtime
 * 会产出全量 DELETE。CronManager::syncFromFetcher() 必须在 fetcher 抛异常时
 * 不调用本方法，才能保留 Last Known Good Runtime（P0-6）。
 * fetcher 成功返回空数组则视为配置侧无任务，按 DELETE 收敛。
 *
 * @see TaskDefinition::fingerprint()
 * @see TaskDefinition::resolveJobId()
 * @see CronManager::applyRows()
 * @see CronManager::syncFromFetcher()
 */
final class ConfigDiff
{
    /**
     * desired 新增 jobId：注册 RuntimeJob；仅 status=STATUS_ENABLED 时武装 Timer。
     */
    public const ADD = 'ADD';

    /**
     * 同一 jobId 的 fingerprint 变化：先清旧 Timer，再换 TaskDefinition / Schedule。
     * 可与 ENABLE / DISABLE 同轮先后发出（先 UPDATE 再改状态）。
     */
    public const UPDATE = 'UPDATE';

    /**
     * runtime 有而 desired 无：停止未来调度。definition 回填当前 Runtime 定义。
     */
    public const DELETE = 'DELETE';

    /**
     * status 从 STATUS_DISABLED(0) 变为 STATUS_ENABLED(1)。
     * Enable ≠ Immediately Run，等待下一次合法 nextRunAt。
     */
    public const ENABLE = 'ENABLE';

    /**
     * status 从 STATUS_ENABLED(1) 变为 STATUS_DISABLED(0)。
     * 清 Timer 但保留 RuntimeJob，与 DELETE 不同。
     */
    public const DISABLE = 'DISABLE';

    /**
     * 身份、status、fingerprint 均未变化。applyOp() 的 default 分支丢弃。
     */
    public const NOOP = 'NOOP';

    /**
     * 以 jobId 为身份，比较 Runtime 定义与本轮 desired 快照，产出最小化 op 列表。
     *
     * 遍历顺序：先扫 $desired（ADD / UPDATE / ENABLE / DISABLE / NOOP），
     * 再扫 $runtime 中 desired 缺失的键（DELETE）。同一 jobId 在 status
     * 与 fingerprint 同时变化时，可能连续追加 UPDATE 与 ENABLE/DISABLE。
     *
     * 空 / null 语义：
     * - 两侧皆空 → 空列表
     * - $runtime 空、$desired 非空 → 全部 ADD
     * - $desired 空、$runtime 非空 → 全部 DELETE（成功空配置，不是 DB 故障）
     * - 参数类型为 array，不会收到 null；本方法写出的 definition 始终非 null
     *   （ADD/UPDATE/ENABLE/DISABLE/NOOP 用 desired，DELETE 用 runtime 当前值）
     * - 返回类型中 definition 标为可空，是为了与 CronManager::applyOp() 签名对齐
     *
     * @param array<string, TaskDefinition> $runtime 当前 Runtime 定义，键为 jobId
     * @param array<string, TaskDefinition> $desired 本轮 DB/配置快照，键为 jobId
     * @return list<array{op:string,jobId:string,definition:?TaskDefinition}>
     */
    public function diff(array $runtime, array $desired): array
    {
        $ops = [];

        foreach ($desired as $jobId => $definition) {
            // 身份只存在于 desired：新任务，不比较 fingerprint / status
            if (!isset($runtime[$jobId])) {
                $ops[] = [
                    'op' => self::ADD,
                    'jobId' => $jobId,
                    'definition' => $definition,
                ];
                continue;
            }

            $current = $runtime[$jobId];
            // status 单独判定 ENABLE/DISABLE；fingerprint() 不含 status
            $statusChanged = $current->status !== $definition->status;
            $metaChanged = $current->fingerprint() !== $definition->fingerprint();

            // DISABLE → ENABLE：元数据也变则先 UPDATE 再 ENABLE，避免只恢复旧 Timer
            if ($statusChanged && $current->status === TaskDefinition::STATUS_DISABLED
                && $definition->status === TaskDefinition::STATUS_ENABLED) {
                if ($metaChanged) {
                    $ops[] = [
                        'op' => self::UPDATE,
                        'jobId' => $jobId,
                        'definition' => $definition,
                    ];
                }
                $ops[] = [
                    'op' => self::ENABLE,
                    'jobId' => $jobId,
                    'definition' => $definition,
                ];
                continue;
            }

            // ENABLE → DISABLE：先按需 UPDATE 定义，再 DISABLE 清 Timer；Job 仍留在 Registry
            if ($statusChanged && $current->status === TaskDefinition::STATUS_ENABLED
                && $definition->status === TaskDefinition::STATUS_DISABLED) {
                if ($metaChanged) {
                    $ops[] = [
                        'op' => self::UPDATE,
                        'jobId' => $jobId,
                        'definition' => $definition,
                    ];
                }
                $ops[] = [
                    'op' => self::DISABLE,
                    'jobId' => $jobId,
                    'definition' => $definition,
                ];
                continue;
            }

            // status 未在 0↔1 之间切换（含两侧同为启用/停用，或其它 status 取值）：只处理调度元数据
            if ($metaChanged) {
                $ops[] = [
                    'op' => self::UPDATE,
                    'jobId' => $jobId,
                    'definition' => $definition,
                ];
                continue;
            }

            // 身份、status、fingerprint 均一致，显式给出 NOOP 便于测试与审计
            $ops[] = [
                'op' => self::NOOP,
                'jobId' => $jobId,
                'definition' => $definition,
            ];
        }

        // desired 缺席 = 配置侧已去掉该任务（含成功拉取空列表），不是 DISABLE
        foreach ($runtime as $jobId => $current) {
            if (!isset($desired[$jobId])) {
                $ops[] = [
                    'op' => self::DELETE,
                    'jobId' => $jobId,
                    'definition' => $current,
                ];
            }
        }

        return $ops;
    }
}
