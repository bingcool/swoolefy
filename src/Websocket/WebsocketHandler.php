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

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Swoole;
use Swoolefy\Core\ServiceDispatch;
use Swoolefy\Core\HandlerInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContent;

class WebsocketHandler extends Swoole implements HandlerInterface
{

    /**
     * 数据分隔符
     */
    const EOF = SWOOLEFY_EOF_FLAG;

    /**
     * 内部指定的ping的endPoint末端
     */
    const PingEndPoint = 'swoolefy/websocket/ping';

    /**
     * __construct
     * @param array $config
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * run
     * @param int $fd
     * @param mixed $payload
     * @param array $extendData
     * @param array $contextData
     * @return mixed
     * @throws \Throwable
     */
    public function run($fd, $payload, array $extendData = [], array $contextData = [])
    {
        try {
            if ($this->isWorkerProcess()) {
                try {
                    // Worker 进程处理客户端帧：先统一解析为 WebsocketPacket，再进入 service.php 路由分发。
                    $packet = WebsocketPacket::parse((int) $fd, (string) $payload, static::EOF);
                } catch (\InvalidArgumentException $exception) {
                    return $this->sendError((int) $fd, $exception->getMessage());
                }
                return $this->handlePacket($packet);
            }

            parent::run($fd, $payload);
            // Task 进程不重新解析原始帧，只恢复 worker 投递过来的 callable 和上下文。
            foreach ($contextData as $key => $value) {
                SwooleContent::set($key, $value);
            }
            list($callable, $params) = $payload;
            $dispatcher = new ServiceDispatch($callable, $params);
            list($fromWorkerId, $taskId, $task) = $extendData;
            $dispatcher->setFromWorkerIdAndTaskId($fromWorkerId, $taskId, $task);
            return $dispatcher->dispatch();
        } catch (\Throwable $throwable) {
            ServiceDispatch::getErrorHandle()->errorMsg($throwable->getMessage(), -1);
            throw $throwable;
        } finally {
            if (!$this->isWorkerProcess() && !$this->isDefer) {
                parent::end();
            }
        }

    }

    public function handlePacket(WebsocketPacket $packet, bool $sendError = true): bool
    {
        try {
            parent::run($packet->getFd(), $packet->getRaw());
            // 将当前消息挂到 Application 上，业务服务可通过 getWebsocketMsg() 读取 request_id、fd 等信息。
            $this->setWebsocketPacket($packet);
            $this->setMixedParams($packet->getParams());
            $this->setServiceHandle($packet->getEndpoint());
            WebsocketConnectionManager::touch($packet->getFd());

            // 框架级 ping 可走统一 JSON 格式，也兼容历史 endpoint ping。
            if ($packet->getType() === WebsocketPacket::TYPE_PING || $this->ping($packet->getEndpoint())) {
                return Swfy::getServer()->push($packet->getFd(), WebsocketResponse::pong($packet->getRequestId()), WEBSOCKET_OPCODE_TEXT, true);
            }

            list($beforeMiddleware, $callable, $afterMiddleware) = ServiceDispatch::getEndPointMapService($packet->getEndpoint());
            $dispatcher = new ServiceDispatch($callable, $packet->getParams());

            if (isset($beforeMiddleware)) {
                $dispatcher->setBeforeMiddleware($beforeMiddleware);
            }

            if (isset($afterMiddleware)) {
                $dispatcher->setAfterMiddleware($afterMiddleware);
            }

            return $dispatcher->dispatch() !== false;
        } catch (\Throwable $throwable) {
            if ($sendError) {
                $this->sendError($packet->getFd(), $throwable->getMessage(), -1, $packet->getRequestId(), $packet->getEndpoint());
            }
            return false;
        } finally {
            if (!$this->isDefer) {
                parent::end();
            }
        }
    }

    /**
     * ping
     * @param string $endPoint
     * @return bool
     */
    public function ping(string $endPoint)
    {
        if (strtolower($endPoint) == self::PingEndPoint) {
            return true;
        }
        return false;
    }

    /**
     * @param string $msg
     * @return string
     */
    private function sendError(int $fd, string $msg = '', int $code = -1, string $requestId = '', string $event = '')
    {
        if (!Swfy::isWorkerProcess()) {
            return false;
        }

        if (!Swfy::getServer()->isEstablished($fd)) {
            return false;
        }

        // 所有框架解析/路由错误都返回统一 error 包，客户端不需要解析 PHP 异常文本。
        return Swfy::getServer()->push($fd, WebsocketResponse::error($msg, $code, $requestId, $event), WEBSOCKET_OPCODE_TEXT, true);
    }

    /**
     * auth
     * @return void
     */
    public function auth()
    {
    }
}

