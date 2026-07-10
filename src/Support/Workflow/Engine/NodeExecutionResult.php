<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Throwable;

/**
 * 单次节点执行结果 —— 驱动引擎后续行为。
 *
 * 各 status 引擎行为：
 *   SUCCESS  → 写 output，DagScheduler 解析下一跳
 *   WAITING  → 快照 + 停止调度（HITL）
 *   RETRY    → 退避后重试 executeNode
 *   FAILED   → Run 失败，触发 onFail
 *   events   → 发布到 EventBus（对外），非 Plugin 钩子
 *
 * @see docs/SwoolefyAI.md §3.2
 */
final class NodeExecutionResult
{
    /**
     * @param array<string, mixed> $events  待对外广播的事件（token、rag、mcp 等）
     * @param array<string, mixed> $metrics 延迟、token 数、重试次数等
     * @param list<string>         $nextHints 可选显式路由 hint
     */
    public function __construct(
        public NodeStatus $status,
        public mixed $output = null,
        public array $events = [],
        public array $metrics = [],
        public array $nextHints = [],
        public ?RetryPolicy $retry = null,
        public ?Throwable $error = null,
    ) {
    }

    /** 构造 SUCCESS 结果。 */
    public static function success(mixed $output = null, array $events = [], array $metrics = []): self
    {
        return new self(NodeStatus::SUCCESS, $output, $events, $metrics);
    }

    /** 构造 WAITING 结果（PauseNode / HITL）。 */
    public static function waiting(mixed $output = null): self
    {
        return new self(NodeStatus::WAITING, $output);
    }

    /** 构造 FAILED 结果。 */
    public static function failed(?Throwable $error = null, mixed $output = null): self
    {
        return new self(NodeStatus::FAILED, $output, error: $error);
    }

    /** 构造 RETRY 结果，可附带自定义 RetryPolicy。 */
    public static function retry(?RetryPolicy $retry = null, ?Throwable $error = null): self
    {
        return new self(NodeStatus::RETRY, retry: $retry, error: $error);
    }
}
