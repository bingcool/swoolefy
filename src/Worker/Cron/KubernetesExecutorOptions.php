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
 * {@see KubernetesExecutor} 的运行时策略（方案 §5.2、§8、§11.2）。
 *
 * 这些值是**运维口径**而不是任务配置：同一台 K8s Agent 上的所有任务共用一套，
 * 因此放在环境变量 / Worker conf 里，而不是 `cron_task` 表。
 */
final class KubernetesExecutorOptions
{
    /** 兜底等待上限：任务没配 timeout 时最多等这么久（秒）。 */
    public const DEFAULT_MAX_WAIT_SECONDS = 3600;

    /**
     * @param list<string> $allowedNamespaces  允许创建 Job 的 Namespace；空数组 = 不限制
     * @param int  $maxWaitSeconds        Executor 等待单个 Job 的硬上限，任何情况下都不会超过
     * @param int  $pollIntervalSeconds   轮询 Job 状态的间隔
     * @param int  $ttlSecondsAfterFinished Job 完成后多久由 K8s 自动回收（0 = 不设，留给运维手工清）
     * @param int  $deadlinePaddingSeconds  activeDeadlineSeconds = timeout + 该值，
     *                                      必须为正，否则 K8s 会先于业务超时杀 Pod
     * @param int  $logTailLines         摘要日志取 Pod stdout 的末尾行数
     * @param int  $messageMaxChars      写进 cron_task_log.message 的最大字符数
     * @param bool $requireTimeout       true = 拒绝 timeout=0 的 K8s 任务（方案 P0-11）
     */
    public function __construct(
        public readonly array $allowedNamespaces = [],
        public readonly int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS,
        public readonly int $pollIntervalSeconds = 3,
        public readonly int $ttlSecondsAfterFinished = 3600,
        public readonly int $deadlinePaddingSeconds = 100,
        public readonly int $logTailLines = 50,
        public readonly int $messageMaxChars = 2000,
        public readonly bool $requireTimeout = true,
    ) {
    }

    /**
     * 从环境变量构造。
     *
     * | 变量 | 含义 |
     * |---|---|
     * | `K8S_ALLOWED_NAMESPACES` | 逗号分隔白名单，如 `production,staging` |
     * | `K8S_MAX_WAIT_SECONDS`   | Executor 等待硬上限 |
     * | `K8S_POLL_INTERVAL`      | 轮询间隔秒 |
     * | `K8S_JOB_TTL_SECONDS`    | Job 完成后的 TTL |
     * | `K8S_DEADLINE_PADDING`   | activeDeadlineSeconds 相对 timeout 的冗余 |
     * | `K8S_LOG_TAIL_LINES`     | 摘要日志行数 |
     * | `K8S_REQUIRE_TIMEOUT`    | `0/false` 可放开「必须配 timeout」的限制 |
     */
    public static function fromEnv(): self
    {
        $namespaces = array_values(array_filter(
            array_map('trim', explode(',', (string) (getenv('K8S_ALLOWED_NAMESPACES') ?: ''))),
            static fn (string $ns): bool => $ns !== '',
        ));
        $requireEnv = strtolower(trim((string) (getenv('K8S_REQUIRE_TIMEOUT') ?: '')));

        return new self(
            allowedNamespaces: $namespaces,
            maxWaitSeconds: self::positiveEnv('K8S_MAX_WAIT_SECONDS', self::DEFAULT_MAX_WAIT_SECONDS),
            pollIntervalSeconds: self::positiveEnv('K8S_POLL_INTERVAL', 3),
            ttlSecondsAfterFinished: max(0, (int) (getenv('K8S_JOB_TTL_SECONDS') ?: 3600)),
            deadlinePaddingSeconds: self::positiveEnv('K8S_DEADLINE_PADDING', 100),
            logTailLines: self::positiveEnv('K8S_LOG_TAIL_LINES', 50),
            messageMaxChars: self::positiveEnv('K8S_MESSAGE_MAX_CHARS', 2000),
            requireTimeout: !in_array($requireEnv, ['0', 'false', 'off', 'no'], true),
        );
    }

    /**
     * Namespace 是否被允许（方案 §5.2 / P0-10）。
     *
     * 白名单为空表示未启用限制——这只应出现在开发环境；生产必须显式配置，
     * 否则一个写错的 `k8s_spec.namespace` 能往任意 Namespace 里塞 Pod。
     */
    public function isNamespaceAllowed(string $namespace): bool
    {
        return $this->allowedNamespaces === [] || in_array($namespace, $this->allowedNamespaces, true);
    }

    /**
     * 本次执行实际的等待秒数。
     *
     * 任务配了 `timeout` 就用它（但不超过运维上限）；没配则回落到 `maxWaitSeconds`。
     * 返回 0 表示「不允许执行」，由调用方判 FAILED。
     */
    public function resolveWaitSeconds(int $taskTimeout): int
    {
        if ($taskTimeout <= 0) {
            return $this->requireTimeout ? 0 : $this->maxWaitSeconds;
        }

        return min($taskTimeout, $this->maxWaitSeconds);
    }

    /**
     * Job 的 `activeDeadlineSeconds`：必须大于业务 timeout，让 schedule-job 先判超时，
     * K8s 只做最后兜底，否则日志会显示 DeadlineExceeded 而看不到业务侧的超时语义。
     */
    public function resolveActiveDeadline(int $waitSeconds): int
    {
        return $waitSeconds > 0 ? $waitSeconds + $this->deadlinePaddingSeconds : 0;
    }

    private static function positiveEnv(string $key, int $default): int
    {
        $value = (int) (getenv($key) ?: 0);

        return $value > 0 ? $value : $default;
    }
}
