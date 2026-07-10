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

namespace Swoolefy\Support\Workflow\Node;

/**
 * 节点可配置超时接口。
 *
 * WorkflowEngine 在 {@see \Swoolefy\Support\Workflow\Engine\WorkflowEngine::resolveNodeTimeout()}
 * 中读取本接口；实现类包括 AINode、AgentParallelNode 等。
 *
 * 约定：
 *   - 返回 > 0：使用该秒数作为节点执行上限（经 TimeoutGuard 包装）
 *   - 返回 0：回退到引擎构造时的 defaultNodeTimeoutSeconds
 *
 * 引擎 default 来自 workflow.php → default_node_timeout_seconds（默认 120）。
 */
interface ConfigurableTimeoutNodeInterface extends NodeInterface
{
    /** 节点级超时秒数；0 表示使用引擎全局默认。 */
    public function configuredTimeoutSeconds(): int;
}
