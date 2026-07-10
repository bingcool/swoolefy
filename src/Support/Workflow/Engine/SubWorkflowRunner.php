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
use Swoolefy\Support\Workflow\Engine\WorkflowRun;

/**
 * 子工作流执行器 —— 供 Tool / 嵌套流程调用主引擎。
 */
final class SubWorkflowRunner
{
    public function __construct(
        private readonly WorkflowEngine $engine,
    ) {
    }

    /**
     * 同步运行子工作流。
     *
     * @param array<string, mixed> $input 子流程输入
     *
     * @return string 子 Run 的 runId
     */
    public function run(CompiledWorkflow $compiled, array $input): string
    {
        return $this->engine->start($compiled, $input);
    }

    /** 获取子 Run 快照。 */
    public function getRun(string $runId): WorkflowRun
    {
        return $this->engine->getRun($runId);
    }

    /** 获取底层引擎实例。 */
    public function engine(): WorkflowEngine
    {
        return $this->engine;
    }
}
