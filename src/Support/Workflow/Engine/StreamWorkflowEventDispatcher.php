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

use Swoolefy\Support\AI\Stream\StreamBridge;

/**
 * 将 Workflow 对外事件转发到 {@see StreamBridge}（SSE / WebSocket）。
 */
final class StreamWorkflowEventDispatcher implements WorkflowEventDispatcherInterface
{
    /** {@inheritdoc} */
    public function publish(string $event, array $payload): void
    {
        StreamBridge::emit($event, $payload);
    }
}
