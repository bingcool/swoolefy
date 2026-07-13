<?php

declare(strict_types=1);

/**
 * Job 轻量模块配置（模版：create 命令复制到 Config/job.php）
 *
 * @see docs/Job.md
 * @see Swoolefy\Support\Job\JobConfig
 */
return [
    'job' => [
        'default_max_attempts' => (int) env('JOB_MAX_ATTEMPTS', 5),
        'base_delay_ms' => (int) env('JOB_BASE_DELAY_MS', 1000),
        'backoff_multiplier' => (float) env('JOB_BACKOFF_MULTIPLIER', 2.0),
        'max_delay_ms' => (int) env('JOB_MAX_DELAY_MS', 300000),
        'handler_timeout_seconds' => (int) env('JOB_HANDLER_TIMEOUT_SECONDS', 120),
        // 死信：redis_list | log_only（零建表）
        'dead_letter' => [
            'driver' => env('JOB_DLQ', 'redis_list'),
            'redis_key_prefix' => env('JOB_DLQ_REDIS_PREFIX', 'job:dead:'),
        ],
    ],
];
