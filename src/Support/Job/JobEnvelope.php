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

namespace Swoolefy\Support\Job;

use Swoolefy\Support\Job\Exception\JobException;

/**
 * 不可变 Job 信封：Redis / AMQP / Kafka 等传输层共用的统一消息结构。
 *
 * 设计要点：
 * - 进程只负责 pop / publish；业务字段与重试计数放在信封里，跨传输可复用 Handler。
 * - 属性全部 readonly：修改 attempt 等请用 {@see withAttempt()} 得到新实例。
 * - 语义为 at-least-once；Exactly-once 依赖 Handler 对 meta.idempotencyKey 等的幂等。
 *
 * 序列化形态（{@see toArray()} / JSON）：
 * ```json
 * {
 *   "v": 1,
 *   "jobId": "job_20260712_ab12cd",
 *   "jobType": "order.paid.notify",
 *   "payload": { "orderId": 10001 },
 *   "meta": { "tenantId": "t1", "traceId": "…", "idempotencyKey": "…" },
 *   "attempt": 1,
 *   "maxAttempts": 5,
 *   "createdAt": 1720764000
 * }
 * ```
 *
 * @see docs/Job.md
 */
final class JobEnvelope
{
    /**
     * @param string $jobId 全局唯一 ID；日志关联与幂等兜底
     * @param string $jobType 业务类型；Registry 路由或进程内写死 Handler
     * @param array<string, mixed> $payload 业务载荷（宜小对象；大内容只放 ref / 路径）
     * @param array<string, mixed> $meta 跨进程上下文，常见键：
     *        tenantId / traceId / idempotencyKey（Handler 幂等键）
     * @param int $attempt 当前投递次数，从 1 起计（首次投递 = 1）
     * @param int $maxAttempts 本信封允许的最大 attempt；与 JobRunner 策略取较小值生效
     * @param int $createdAt 创建时间戳（秒）；缺省 0 时 fromArray/make 会补 time()
     * @param int $v 信封协议版本；当前固定为 1，便于日后演进
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $jobType,
        public readonly array $payload,
        public readonly array $meta = [],
        public readonly int $attempt = 1,
        public readonly int $maxAttempts = 5,
        public readonly int $createdAt = 0,
        public readonly int $v = 1,
    ) {
    }

    /**
     * 生产侧工厂：生成新 jobId，attempt=1，maxAttempts 取自 $policy（缺省 JobRetryPolicy）。
     *
     * 典型用法：{@see JobPublisher::dispatch()} 内部调用；业务也可直接 make 后自行 push。
     *
     * @param string $jobType 非空业务类型，如 order.paid.notify
     * @param array<string, mixed> $payload 业务数据
     * @param array<string, mixed> $meta 可选上下文（tenantId / traceId / idempotencyKey …）
     * @param JobRetryPolicy|null $policy 决定写入信封的 maxAttempts；为 null 用默认策略
     */
    public static function make(
        string $jobType,
        array $payload,
        array $meta = [],
        ?JobRetryPolicy $policy = null,
    ): self {
        $policy ??= new JobRetryPolicy();

        return new self(
            jobId: JobId::generate(),
            jobType: $jobType,
            payload: $payload,
            meta: $meta,
            attempt: 1,
            maxAttempts: $policy->maxAttempts,
            createdAt: time(),
            v: 1,
        );
    }

    /**
     * 将旧版非信封 payload 包装为信封，便于渐进迁移。
     *
     * 判定逻辑：
     * 1) 字符串 → 尝试 json_decode；失败则包成 `['value' => $raw]`
     * 2) 非数组 → 同样包成 `['value' => $raw]`
     * 3) 已含 `(jobType + jobId)` 或 `(v + jobType)` → 视为信封，走 {@see fromArray()}
     * 4) 否则把整个数组当作 payload，用 $defaultJobType 调用 {@see make()}
     *
     * @param array<string, mixed>|string|mixed $raw 队列原始消息
     * @param string $defaultJobType 非信封消息时使用的默认类型
     */
    public static function wrapLegacy(mixed $raw, string $defaultJobType): self
    {
        // 字符串先尝试 JSON 解码；失败则包成 {value: raw}
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : ['value' => $raw];
        }
        if (!is_array($raw)) {
            $raw = ['value' => $raw];
        }
        // 已是信封结构则直接反序列化，否则用默认 jobType 包装
        if (isset($raw['jobType'], $raw['jobId']) || isset($raw['v'], $raw['jobType'])) {
            return self::fromArray($raw);
        }

        return self::make($defaultJobType, $raw);
    }

    /**
     * 从数组（通常为 JSON 解码结果）还原信封。
     *
     * 校验：
     * - jobType 必须为非空字符串，否则抛 {@see JobException}
     * - payload 缺省 `[]`；若存在但非 array 则抛异常
     * - meta 非 array 时降级为 `[]`
     * - jobId 缺失或空串时补 {@see JobId::generate()}（兼容半成品消息）
     * - attempt / maxAttempts 至少为 1
     *
     * @param array<string, mixed> $data 与 {@see toArray()} 同形的关联数组
     *
     * @throws JobException
     */
    public static function fromArray(array $data): self
    {
        $jobType = $data['jobType'] ?? null;
        if (!is_string($jobType) || $jobType === '') {
            throw new JobException('JobEnvelope requires non-empty jobType');
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            throw new JobException('JobEnvelope payload must be an array');
        }

        $meta = $data['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        // 缺 jobId 时补生成，兼容半成品消息
        $jobId = $data['jobId'] ?? null;
        if (!is_string($jobId) || $jobId === '') {
            $jobId = JobId::generate();
        }

        return new self(
            jobId: $jobId,
            jobType: $jobType,
            payload: $payload,
            meta: $meta,
            attempt: max(1, (int) ($data['attempt'] ?? 1)),
            maxAttempts: max(1, (int) ($data['maxAttempts'] ?? 5)),
            createdAt: (int) ($data['createdAt'] ?? time()),
            v: (int) ($data['v'] ?? 1),
        );
    }

    /**
     * 转为可 JSON / 可入队的关联数组（字段顺序稳定，便于日志与比对）。
     *
     * @return array{
     *     v: int,
     *     jobId: string,
     *     jobType: string,
     *     payload: array<string, mixed>,
     *     meta: array<string, mixed>,
     *     attempt: int,
     *     maxAttempts: int,
     *     createdAt: int
     * }
     */
    public function toArray(): array
    {
        return [
            'v' => $this->v,
            'jobId' => $this->jobId,
            'jobType' => $this->jobType,
            'payload' => $this->payload,
            'meta' => $this->meta,
            'attempt' => $this->attempt,
            'maxAttempts' => $this->maxAttempts,
            'createdAt' => $this->createdAt,
        ];
    }

    /**
     * 返回仅 attempt 不同的新信封（不可变拷贝）。
     *
     * 供 {@see JobRunner} 在 requeue 前把 attempt+1 写回下一跳消息；
     * 其它字段（jobId / payload / meta / maxAttempts …）保持不变。
     * $attempt 小于 1 时钳制为 1。
     */
    public function withAttempt(int $attempt): self
    {
        return new self(
            jobId: $this->jobId,
            jobType: $this->jobType,
            payload: $this->payload,
            meta: $this->meta,
            attempt: max(1, $attempt),
            maxAttempts: $this->maxAttempts,
            createdAt: $this->createdAt,
            v: $this->v,
        );
    }

    /**
     * 读取 meta 中的字符串字段；非字符串或空串时返回 $default。
     *
     * 便于 Handler 安全取 tenantId / traceId / idempotencyKey，避免直接下标告警。
     */
    public function metaString(string $key, ?string $default = null): ?string
    {
        $value = $this->meta[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
