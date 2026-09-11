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

namespace Swoolefy\Worker\Kubernetes;

use Swoolefy\Exception\AbstractSwoolefyExeption;

/**
 * Kubernetes API 调用失败。
 *
 * 由 {@see Client} 抛出。Cron 层的 {@see \Swoolefy\Worker\Cron\KubernetesExecutor}
 * 捕获后映射为 ExecutionResult，不允许逃逸到 Worker。
 *
 * statusCode = 0 表示连接层失败（DNS / 连接超时 / TLS），不是 API Server 返回的状态码。
 * reason 取自 Kubernetes Status 对象的 `reason` 字段（如 `AlreadyExists`、`NotFound`、
 * `Forbidden`），比状态码更精确。
 */
class ApiException extends AbstractSwoolefyExeption
{
    /**
     * @param int    $statusCode HTTP 状态码；0 = 未拿到响应（连接层失败）
     * @param string $reason     Kubernetes Status.reason
     * @param string $body       原始响应体（已截断），仅用于日志
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $reason = '',
        public readonly string $body = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code !== 0 ? $code : $statusCode, $previous);
    }

    /**
     * Create 时同名对象已存在。Executor 据此走幂等分支：GET 回来比对 label。
     */
    public function isAlreadyExists(): bool
    {
        return $this->statusCode === 409 || $this->reason === 'AlreadyExists';
    }

    /**
     * 资源不存在。Deployment 404 → 不建 Job；Job 404 → 可能是 TTL 已回收。
     */
    public function isNotFound(): bool
    {
        return $this->statusCode === 404 || $this->reason === 'NotFound';
    }

    /**
     * 认证 / 授权失败。多半是 RBAC 没配全，重试无意义。
     */
    public function isForbidden(): bool
    {
        return in_array($this->statusCode, [401, 403], true)
            || in_array($this->reason, ['Unauthorized', 'Forbidden'], true);
    }

    /**
     * 可能是瞬时故障（限流 / API Server 抖动 / 连接层失败）。
     *
     * Client 内部不自动重试（会把 Job 建重），交给 CronManager 的 retry 再 attempt。
     */
    public function isRetryable(): bool
    {
        return $this->statusCode === 0 || $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
