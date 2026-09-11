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
 * Kubernetes API 最小访问面（方案 §13）。
 *
 * 边界：
 * - 只做 HTTP，不含任何调度 / 重试 / 状态判定语义（那些在 {@see KubernetesExecutor}）。
 * - 一律走 Kubernetes HTTP API，**禁止** `kubectl` / `proc_open`（方案 §0.1、§20）。
 * - 失败统一抛 {@see KubernetesApiException}，不返回 null 表示错误。
 * - Client 内部不做自动重试：Create 重试会把 Job 建重，重试交给 CronManager 的
 *   `retry` 走新的 attempt（方案 §14）。
 *
 * 所有方法都必须在协程内调用（Swoole 会 hook curl），与 {@see HttpExecutor} 同类。
 *
 * @see KubernetesClient 生产实现
 */
interface KubernetesClientInterface
{
    /**
     * 读取 Deployment 全量对象（用于取 `spec.template` 与 `spec.selector`）。
     *
     * @return array<string, mixed> 反序列化后的 Deployment
     * @throws KubernetesApiException 404 表示 Deployment 不存在 → 不得建 Job
     */
    public function getDeployment(string $namespace, string $name): array;

    /**
     * 创建 Job。
     *
     * @param array<string, mixed> $job 完整 batch/v1 Job 对象
     * @return array<string, mixed> API Server 返回的 Job（含 metadata.uid）
     * @throws KubernetesApiException 409 = 同名 Job 已存在，调用方需走幂等分支
     */
    public function createJob(string $namespace, array $job): array;

    /**
     * 读取 Job 当前状态（轮询终态用）。
     *
     * @return array<string, mixed>
     * @throws KubernetesApiException 404 可能是 TTL 已回收
     */
    public function getJob(string $namespace, string $name): array;

    /**
     * 删除 Job，级联删 Pod（`propagationPolicy=Background`）。
     *
     * 用于 Timeout / Cancel（方案 §10、§11.1）。已不存在时应视为成功，
     * 便于「Guard 删过一次、Executor 再删一次」这类重复调用。
     *
     * @return bool true=已删除或本就不存在
     */
    public function deleteJob(string $namespace, string $name): bool;

    /**
     * 按 label 选择器列 Pod（取日志前先定位 Pod）。
     *
     * 用 `schedule-job.exec-batch-id` + `schedule-job.attempt` 定位，
     * 不依赖 Pod 名前缀（方案 §12）。
     *
     * @return list<array<string, mixed>> items 列表，可能为空
     */
    /**
     * 按 label 选择器列 Job（崩溃恢复用 exec-batch-id 反查，方案 §11.3）。
     *
     * @return list<array<string, mixed>>
     */
    public function listJobs(string $namespace, string $labelSelector): array;

    public function listPods(string $namespace, string $labelSelector): array;

    /**
     * 拉取 Pod 日志尾部。
     *
     * @param int $tailLines 只取末尾 N 行，避免把整个 stdout 拉进 Worker 内存
     * @return string 失败时返回空串而不是抛异常（日志缺失不应改变执行结论）
     */
    public function getPodLogs(string $namespace, string $pod, string $container = '', int $tailLines = 50): string;
}
