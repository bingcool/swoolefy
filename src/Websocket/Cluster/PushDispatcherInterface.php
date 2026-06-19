<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;

interface PushDispatcherInterface
{
    public function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool;

    public function pushEventToUser(Server $server, string $userId, string $event, $data = []): int;

    public function pushEventToGroup(Server $server, string $group, string $event, $data = []): int;

    public function broadcastEvent(Server $server, string $event, $data = []): int;
}
