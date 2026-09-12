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

namespace Swoolefy\Support\Kubernetes;

/**
 * Kubernetes API 最小访问面。
 *
 * 边界：
 * - 只做 HTTP，不含调度 / 重试 / 状态判定。
 * - 一律走 Kubernetes HTTP API，禁止 kubectl / proc_open。
 * - 失败统一抛 {@see ApiException}，不返回 null 表示错误。
 * - Client 内部不做自动重试：Create 重试会把 Job 建重。
 *
 * 所有方法都必须在协程内调用（Swoole 会 hook curl）。
 */
interface ClientInterface
{
    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getDeployment(string $namespace, string $name): array;

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function createJob(string $namespace, array $job): array;

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getJob(string $namespace, string $name): array;

    /**
     * 删除 Job，级联删 Pod（`propagationPolicy=Background`）。已不存在视为成功。
     *
     * @throws ApiException
     */
    public function deleteJob(string $namespace, string $name): bool;

    /**
     * @return list<array<string, mixed>>
     * @throws ApiException
     */
    public function listJobs(string $namespace, string $labelSelector): array;

    /**
     * @return list<array<string, mixed>>
     * @throws ApiException
     */
    public function listPods(string $namespace, string $labelSelector): array;

    /**
     * 拉取 Pod 日志尾部。失败返回空串，不抛异常。
     */
    public function getPodLogs(string $namespace, string $pod, string $container = '', int $tailLines = 50): string;
}
