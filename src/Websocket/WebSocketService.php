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

use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\BService;
use Swoolefy\Core\Dto\BaseResponseDto;
use Swoolefy\Core\Swfy;
use Swoolefy\Exception\SystemException;

class WebSocketService extends BService
{
    /**
     * push websocket
     * @param int $fd
     * @param BaseResponseDto $dataDto
     * @param int $opcode
     * @param int $finish
     * @return bool
     */
    public function push(
        int $fd,
        BaseResponseDto $dataDto,
        int $opcode = 1,
        int $finish = 1
    ): bool {
        if (!BaseServer::isWebsocketApp()) {
            throw new SystemException("WebSocketService::push() this method only can be called by websocket server!");
        }

        if (!Swfy::getServer()->isEstablished($fd)) {
            throw new SystemException("Websocket connection closed");
        }

        if (empty($dataDto->trace_id)) {
            $dataDto->trace_id = $this->getTraceId();
        }

        $data = json_encode($dataDto->toArray(), JSON_UNESCAPED_UNICODE);

        return Swfy::getServer()->push($fd, $data, $opcode, (int) $finish);
    }

    /**
     * push websocket raw payload
     * @param int $fd
     * @param string $payload
     * @param int $opcode
     * @param int $finish
     * @return bool
     */
    public function pushRaw(int $fd, string $payload, int $opcode = WEBSOCKET_OPCODE_TEXT, int $finish = 1): bool
    {
        if (!BaseServer::isWebsocketApp()) {
            throw new SystemException("WebSocketService::pushRaw() this method only can be called by websocket server!");
        }

        if (!Swfy::getServer()->isEstablished($fd)) {
            throw new SystemException("Websocket connection closed");
        }

        return Swfy::getServer()->push($fd, $payload, $opcode, (int) $finish);
    }

    /**
     * push websocket unified event
     * @param int $fd
     * @param string $event
     * @param mixed $data
     * @return bool
     */
    public function pushEvent(int $fd, string $event, $data = []): bool
    {
        return WebsocketConnectionManager::pushEventToFd(Swfy::getServer(), $fd, $event, $data);
    }

    public function pushToUser(string $userId, string $event, $data = []): int
    {
        return WebsocketConnectionManager::pushEventToUser(Swfy::getServer(), $userId, $event, $data);
    }

    public function pushToGroup(string $group, string $event, $data = []): int
    {
        return WebsocketConnectionManager::pushEventToGroup(Swfy::getServer(), $group, $event, $data);
    }

    public function broadcast(string $event, $data = []): int
    {
        return WebsocketConnectionManager::broadcastEvent(Swfy::getServer(), $event, $data);
    }

    public function joinWebsocketGroup(string $group): bool
    {
        return WebsocketConnectionManager::joinGroup((int) $this->fd, $group);
    }

    public function leaveWebsocketGroup(string $group): bool
    {
        return WebsocketConnectionManager::leaveGroup((int) $this->fd, $group);
    }

    /**
     * getWebsocketMsg 获取websocket的信息
     * @return WebsocketPacket
     */
    public function getWebsocketMsg(): WebsocketPacket
    {
        return Application::getApp()->getWebsocketMsg();
    }
}
