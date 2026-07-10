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

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;

/**
 * 单次节点执行的运行时上下文。
 * 贯穿 beforeExecute → execute → afterExecute 等钩子。
 */
final class RunContext
{
    /** @param array<string, mixed> $meta 扩展元数据 */
    public function __construct(
        /** Run 唯一 id。 */
        public readonly string $runId,
        /** 已编译工作流。 */
        public readonly CompiledWorkflow $compiled,
        /** 当前重试次数，从 1 开始。 */
        public int $attempt = 1,
        public array $meta = [],
        /** 引擎解析后的节点超时秒数；0 表示不限制。 */
        public readonly float $nodeTimeoutSeconds = 0,
    ) {
    }

    /** 工作流 id 简写。 */
    public function workflowId(): string
    {
        return $this->compiled->workflowId();
    }

    /** 当前执行 attempt 序号。 */
    public function attempt(): int
    {
        return $this->attempt;
    }
}
