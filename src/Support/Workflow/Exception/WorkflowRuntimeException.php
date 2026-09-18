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

namespace Swoolefy\Support\Workflow\Exception;

/**
 * Runtime 基础设施 / 并发控制异常。
 *
 * 与业务失败（节点 FAILED、Timeout、Saga）隔离：不得转为 Node FAILED、
 * 不得 onFail、不得 Saga、不得把 Run 写成 FAILED、不得再用 stale 快照 save()。
 *
 * 仍继承 {@see WorkflowException}，因此 AbstractNode / Engine 必须先 catch 本类。
 */
class WorkflowRuntimeException extends WorkflowException
{
}
