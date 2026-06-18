<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Core\Swfy;

/**
 * 每节点 1 个专用进程，订阅本节点 Redis 频道并投递推送。
 *
 * 外部进程（ExternalPushPublisher）与远端 Worker 的推送最终都到这里：
 * PUBLISH ws:push:{app}:{server_id} → 本进程 SUBSCRIBE → PushDeliveryHandler → server->push()
 *
 * SUBSCRIBE 会阻塞协程，不能放在 worker 内执行。
 */
class WebsocketPushSubscriberProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled()) {
            return;
        }

        $channel = ClusterConfig::pushChannelForServer(ClusterNodeIdentity::getServerId());
        \Swoole\Coroutine::create(function () use ($channel) {
            while (true) {
                try {
                    // 断线后自动重连，保证推送总线可用
                    ClusterRedisClient::subscribe($channel, function (string $payload) {
                        $this->handlePayload($payload);
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }
            }
        });
    }

    private function handlePayload(string $payload): void
    {
        $message = PushMessage::decode($payload);
        if ($message === null) {
            return;
        }

        // 订阅进程与 Worker 共享同一 Server 实例
        $server = Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            return;
        }

        PushDeliveryHandler::deliver($server, $message);
    }

    protected function onHandleException(\Throwable $throwable)
    {
        if (method_exists(BaseServer::class, 'catchException')) {
            BaseServer::catchException($throwable);
            return;
        }

        trigger_error($throwable->getMessage(), E_USER_WARNING);
    }
}
