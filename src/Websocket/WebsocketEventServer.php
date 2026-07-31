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

namespace Swoolefy\Websocket;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\EventInterface\WebsocketEventInterface;

abstract class WebsocketEventServer extends WebsocketServer implements WebsocketEventInterface
{

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onOpen
     * @param Server $server
     * @param object $request
     * @return void
     */
    abstract public function onOpen(Server $server, Request $request);

    /**
     * onRequest
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function onRequest(Request $request, Response $response)
    {
        $appInstance = new \Swoolefy\Core\App();
        $appInstance->run($request, $response);
    }

    /**
     * 完整消息帧（分片重组后）在独立协程内 dispatch：touch Redis 索引 + Socket.IO / 原生 WS。
     *
     * 由 WebsocketServer::on('message') 在 WebsocketFrameAssembler 收齐帧后调用；
     * 分片重组无 Redis IO，留在 WebsocketServer 回调外层。
     *
     * 注意：此处不能用 goApp()/EventApp。goApp 会绑定 EventController，而下游
     * WebsocketHandler::run()/handlePacket() 需要把自身注册为 Application；
     * 同协程二次 setApp 会抛 “Application already bound”，Socket.IO ack 变成 dispatch failed。
     * Redis 无 EventApp 时走 ClusterRedisClient 协程级连接缓存即可。
     */
    public function dispatchMessageFrame(Server $server, Frame $frame, array $websocketConfig): void
    {
        $fd = (int) $frame->fd;
        // 双重校验：上层 message 已挡 opening；此处防止竞态窗口漏入
        if (!WebsocketConnectionManager::isConnectionReady($fd)) {
            return;
        }

        $contextData = SwooleContext::snapshot();
        \Swoole\Coroutine::create(function () use ($server, $frame, $websocketConfig, $fd, $contextData) {
            foreach ($contextData as $key => $value) {
                SwooleContext::set($key, $value);
            }

            try {
                // 下层 Socket.IO / 原生 WS 以 WebsocketHandler 作为 Application；此处先 touch
                WebsocketConnectionManager::touch($fd);

                $connection = WebsocketConnectionManager::getConnection($fd);
                // 同一个 onMessage 入口按连接标记区分普通 WebSocket 与 Socket.IO 协议。
                if (!empty($websocketConfig['socketio']['enable']) && !empty($connection['is_socketio'])) {
                    SocketIO\SocketIOHandler::onMessage($server, $frame, $websocketConfig, true);

                    return;
                }
                static::onMessage($server, $frame);
            } catch (\Throwable $throwable) {
                BaseServer::catchException($throwable);
            }
        });
    }

    /**
     * onMessage
     * @param Server $server
     * @param Frame $frame
     * @return void
     * @throws \Throwable
     */
    public function onMessage(Server $server, Frame $frame)
    {
        $fd     = $frame->fd;
        $data   = $frame->data;
        $opcode = $frame->opcode;
        // 进入此方法前，WebsocketServer 已完成分片重组，frame 必为完整消息
        if ($opcode == WEBSOCKET_OPCODE_TEXT) {
            $appInstance = new WebsocketHandler();
            $appInstance->run($fd, $data);
        } else if ($opcode == WEBSOCKET_OPCODE_BINARY) {
            static::onMessageFromBinary($server, $frame);
        } else if ($opcode == WEBSOCKET_OPCODE_PING) {
            $pingFrame = new Frame;
            $pingFrame->opcode = WEBSOCKET_OPCODE_PONG;
            $server->push($frame->fd, $pingFrame);
        } else if ($opcode == WEBSOCKET_OPCODE_CLOSE) {
            static::onMessageFromClose($server, $frame);
        }
    }

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return bool
     * @throws \Throwable
     */
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        list($callable, $taskData, $contextData, $fd) = $data;
        $appInstance = new WebsocketHandler();
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task], $contextData ?? []);
        return true;
    }

    /**
     * onFinish
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return void
     */
    abstract public function onFinish(Server $server, int $task_id, $data);

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage(Server $server, int $from_worker_id, $message);

    /**
     * onClose
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose(Server $server, int $fd);

    /**
     * onMessageFromBinary
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    abstract public function onMessageFromBinary(Server $server, Frame $frame);

    /**
     * onMessageFromClose
     * @param Server $server
     * @param mixed $frame
     * @return void
     */
    abstract public function onMessageFromClose(Server $server, Frame $frame);

}
