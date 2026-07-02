<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

/** 单次节点执行状态。 */
enum NodeStatus: string
{
    /** 执行成功，继续路由下一节点。 */
    case SUCCESS = 'success';
    /** 等待人工介入（HITL / PauseNode）。 */
    case WAITING = 'waiting';
    /** 不可恢复失败。 */
    case FAILED = 'failed';
    /** 跳过（Phase 2+ 并行/条件跳过）。 */
    case SKIPPED = 'skipped';
    /** 请求重试。 */
    case RETRY = 'retry';
    /** Saga 补偿中（Phase 4）。 */
    case COMPENSATING = 'compensating';
}
