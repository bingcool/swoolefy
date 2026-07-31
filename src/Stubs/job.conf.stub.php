<?php

declare(strict_types=1);

/**
 * Job 轻量模块配置模版。
 *
 * 用法：
 * - `php cli.php create App` 时自动复制为 `APP_PATH/Config/job.php`
 * - 已有应用可手动：`cp src/Stubs/job.conf.stub.php App/Config/job.php`
 *
 * 读取：{@see \Swoolefy\Support\Job\JobConfig}（经 {@see \Swoolefy\Support\Job\JobComponentFactory} 装配）。
 * 说明：本文件只规范**默认重试策略 / 超时 / 死信 key**；最终失败怎么落（dead 回调）
 * 仍由消费进程自行注入，自由度在进程侧（不在此配置「失败处理类」）。
 *
 * 环境变量优先级（整型项）：大多通过 ApplicationConfig::pickIntEnvFirst，
 * **环境变量优先于本文件**；未设 env 时用本文件默认值。
 *
 * @see docs/Job.md
 * @see \Swoolefy\Support\Job\JobConfig
 * @see \Swoolefy\Support\Job\JobRetryPolicy
 * @see \Swoolefy\Support\Job\RedisDeadLetter
 */
return [
    'job' => [
        // ---------- 重试策略（写入信封 maxAttempts，并构造 JobRetryPolicy）----------

        /**
         * 默认最大尝试次数（attempt 从 1 起计：首次投递=1）。
         * JobRunner 实际生效为 min(信封 maxAttempts, 本策略 maxAttempts)。
         * env: JOB_MAX_ATTEMPTS
         */
        'default_max_attempts' => (int) env('JOB_MAX_ATTEMPTS', 5),

        /**
         * 首次失败后再投的基础延迟（毫秒）。
         * 实际延迟 ≈ base_delay_ms * (backoff_multiplier ^ (attempt-1)) + jitter，
         * 且不超过 max_delay_ms。
         * 注意：Job 层单位是 ms；对接 Redis Queue::retry 时 demo 会 ceil(ms/1000) 转成秒。
         * env: JOB_BASE_DELAY_MS
         */
        'base_delay_ms' => (int) env('JOB_BASE_DELAY_MS', 1000),

        /**
         * 指数退避倍率（≥ 1.0）。例如 2.0 → 1s、2s、4s、8s…
         * env: JOB_BACKOFF_MULTIPLIER
         */
        'backoff_multiplier' => (float) env('JOB_BACKOFF_MULTIPLIER', 2.0),

        /**
         * 单次重试延迟上限（毫秒）。默认 300000 = 5 分钟。
         * env: JOB_MAX_DELAY_MS
         */
        'max_delay_ms' => (int) env('JOB_MAX_DELAY_MS', 300000),

        /**
         * Handler 执行超时（秒）。JobRunner 经 TimeoutGuard 在协程内强制执行；
         * 超时走 retry/dead-letter。注意：只能中断可协作协程 IO，阻塞扩展须自配网络超时。
         * env: JOB_HANDLER_TIMEOUT_SECONDS
         */
        'handler_timeout_seconds' => (int) env('JOB_HANDLER_TIMEOUT_SECONDS', 120),

        // ---------- 死信（零建表）----------
        // 真正是否调用 RedisDeadLetter::push，仍取决于进程注入的 $dead 闭包。
        // 此处只提供 driver 标识与 Redis key 前缀，供 JobComponentFactory::redisDeadLetter 使用。

        'dead_letter' => [
            /**
             * 死信驱动标识：
             * - redis_list：推荐；List key = {redis_key_prefix}{queue}，如 job:dead:default
             * - log_only：仅标识「进程侧打日志即可」，框架不强行写库/建表
             * env: JOB_DLQ
             */
            'driver' => env('JOB_DLQ', 'redis_list'),

            /**
             * Redis 死信 List 的 key 前缀（后缀由队列名拼接，默认 queue=default）。
             * 例：prefix=job:dead: → key job:dead:default
             * 重放：RedisDeadLetter::replay() 或 Console/replay_dead_letter.php
             * env: JOB_DLQ_REDIS_PREFIX
             */
            'redis_key_prefix' => env('JOB_DLQ_REDIS_PREFIX', 'job:dead:'),
        ],
    ],
];
