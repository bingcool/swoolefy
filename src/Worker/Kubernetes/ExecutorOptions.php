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

/**
 * Kubernetes 执行的运行时策略（白名单、等待上限、Job TTL）。
 *
 * 这些值是运维口径而不是任务配置：同一台 Agent 上的所有 K8s 任务共用一套。
 */
final class ExecutorOptions
{
    public const DEFAULT_MAX_WAIT_SECONDS = 3600;

    /**
     * @param list<string> $allowedNamespaces
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

    public function isNamespaceAllowed(string $namespace): bool
    {
        return $this->allowedNamespaces === [] || in_array($namespace, $this->allowedNamespaces, true);
    }

    /**
     * 返回 0 表示不允许执行（调用方应判 FAILED）。
     */
    public function resolveWaitSeconds(int $taskTimeout): int
    {
        if ($taskTimeout <= 0) {
            return $this->requireTimeout ? 0 : $this->maxWaitSeconds;
        }

        return min($taskTimeout, $this->maxWaitSeconds);
    }

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
