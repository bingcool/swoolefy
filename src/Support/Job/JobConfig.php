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

use Swoolefy\Support\ApplicationConfig;

/**
 * Job 轻量模块配置（APP_PATH/Config/job.php）。
 *
 * 模版：src/Stubs/job.conf.stub.php（create 命令复制）。
 */
final class JobConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('job.php'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测注入
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    private function section(): array
    {
        $job = $this->config['job'] ?? $this->config;

        return is_array($job) ? $job : [];
    }

    public function defaultMaxAttempts(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'default_max_attempts',
            'JOB_MAX_ATTEMPTS',
            5,
        );
    }

    public function baseDelayMs(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'base_delay_ms',
            'JOB_BASE_DELAY_MS',
            1000,
        );
    }

    public function backoffMultiplier(): float
    {
        // float 配置：文件优先，再读环境变量（与 pickIntEnvFirst 语义略有不同）
        $section = $this->section();
        $value = $section['backoff_multiplier'] ?? null;
        if (is_numeric($value)) {
            return (float) $value;
        }

        $env = getenv('JOB_BACKOFF_MULTIPLIER');
        if ($env !== false && is_numeric($env)) {
            return (float) $env;
        }

        return 2.0;
    }

    public function maxDelayMs(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'max_delay_ms',
            'JOB_MAX_DELAY_MS',
            300_000,
        );
    }

    public function handlerTimeoutSeconds(): float
    {
        return (float) ApplicationConfig::pickIntEnvFirst(
            $this->section(),
            'handler_timeout_seconds',
            'JOB_HANDLER_TIMEOUT_SECONDS',
            120,
        );
    }

    public function retryPolicy(): JobRetryPolicy
    {
        return new JobRetryPolicy(
            maxAttempts: $this->defaultMaxAttempts(),
            baseDelayMs: $this->baseDelayMs(),
            backoffMultiplier: $this->backoffMultiplier(),
            maxDelayMs: $this->maxDelayMs(),
            jitterRatio: 0.2,
        );
    }

    /** redis_list | log_only */
    public function deadLetterDriver(): string
    {
        $dead = $this->section()['dead_letter'] ?? [];
        if (!is_array($dead)) {
            $dead = [];
        }

        return ApplicationConfig::pickStringEnvFirst(
            $dead,
            'driver',
            'JOB_DLQ',
            'redis_list',
        );
    }

    public function deadLetterRedisKeyPrefix(): string
    {
        $dead = $this->section()['dead_letter'] ?? [];
        if (!is_array($dead)) {
            $dead = [];
        }

        return ApplicationConfig::pickStringEnvFirst(
            $dead,
            'redis_key_prefix',
            'JOB_DLQ_REDIS_PREFIX',
            'job:dead:',
        );
    }
}
