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

/**
 * WebSocket 业务 Service 基类。
 *
 * 封装推送、加组/退组、获取当前消息包等 API；集群模式下 push 方法自动跨节点扇出。
 *
 * ## 安全相关 API
 *
 * | 方法 | 说明 |
 * |------|------|
 * | pushToUser | 要求非空 user_id；目标须已通过 auth 绑定 user 索引 |
 * | joinWebsocketGroup | 写入索引前经 group.join_authorizer 鉴权 |
 * | getWebsocketUserId | 握手鉴权 callback 或 query uid 绑定的身份 |
 *
 * @see WebsocketConnectionManager
 * @see WebsocketAuthenticator
 */
class WebsocketService extends BService
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
            throw new SystemException("WebsocketService::push() this method only can be called by websocket server!");
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
            throw new SystemException("WebsocketService::pushRaw() this method only can be called by websocket server!");
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

    /**
     * 向用户所有在线连接推送（集群下跨节点）。
     *
     * @throws SystemException user_id 为空时（禁止匿名 pushToUser）
     *
     * @return int 成功投递的连接数
     */
    public function pushToUser(string $userId, string $event, $data = []): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new SystemException('pushToUser requires a non-empty user_id');
        }

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

    /**
     * 当前连接加入小组（经 group.join_authorizer 鉴权后写入 Table + Redis）。
     *
     * @param array $params 客户端 join 参数（invite_code、password 等），透传给鉴权器
     *
     * @return bool 鉴权失败或参数非法时返回 false，原因见 WebsocketConnectionManager::getLastJoinDenyReason()
     */
    public function joinWebsocketGroup(string $group, array $params = []): bool
    {
        return WebsocketConnectionManager::joinGroup((int) $this->fd, $group, $params);
    }

    public function leaveWebsocketGroup(string $group): bool
    {
        return WebsocketConnectionManager::leaveGroup((int) $this->fd, $group);
    }

    /** 当前连接绑定的 user_id（握手 query uid/user_id 或鉴权 callback） */
    public function getWebsocketUserId(): string
    {
        $connection = WebsocketConnectionManager::getConnection((int) $this->fd);

        return (string) ($connection['user_id'] ?? '');
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
