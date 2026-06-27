<?php

namespace Swoolefy\Websocket\Offline;

use Swoole\WebSocket\Server;

/**
 * 将 callable / `[Class, method]` 适配为 {@see OfflineReconnectHookInterface}。
 *
 * 供 OfflineReconnectHookFactory 内部使用；业务也可直接 new 注入单测。
 */
class CallableOfflineReconnectHook implements OfflineReconnectHookInterface
{
    /** @var callable */
    private $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function onReconnect(Server $server, int $fd, string $userId, int $replayedCount): void
    {
        ($this->callback)($server, $fd, $userId, $replayedCount);
    }
}
