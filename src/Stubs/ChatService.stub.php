<?php
namespace __APP_NAMESPACE__\Service;

use Swoolefy\Websocket\WebSocketService;

class ChatService extends WebSocketService
{
    public function sendMessage(array $params)
    {
        $packet = $this->getWebsocketMsg();
        $group = (string) ($params['group'] ?? 'public');
        $message = (string) ($params['message'] ?? '');

        // group 内广播，适配普通 WebSocket 统一格式；Socket.IO 客户端会通过 ack 获得调用结果。
        $this->pushToGroup($group, 'chat.message', [
            'group' => $group,
            'message' => $message,
            'from_fd' => $packet->getFd(),
        ]);
    }

    /**
     * A→B 单聊：向 to_user_id 推送 chat.private，依赖握手 uid 绑定与 pushToUser。
     */
    public function sendPrivateMessage(array $params)
    {
        $toUserId = trim((string) ($params['to_user_id'] ?? $params['to_uid'] ?? ''));
        $message = (string) ($params['message'] ?? '');

        if ($toUserId === '') {
            throw new \InvalidArgumentException('to_user_id is required');
        }

        $fromUserId = $this->getWebsocketUserId();
        $count = $this->pushToUser($toUserId, 'chat.private', [
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'message' => $message,
            'ts' => time(),
        ]);

        if ($count === 0) {
            throw new \InvalidArgumentException('recipient offline or not connected');
        }
    }

    public function joinGroup(array $params)
    {
        $group = (string) ($params['group'] ?? 'public');
        $this->joinWebsocketGroup($group);
        $this->pushEvent($this->getWebsocketMsg()->getFd(), 'group.joined', ['group' => $group]);
    }

    public function leaveGroup(array $params)
    {
        $group = (string) ($params['group'] ?? 'public');
        $this->leaveWebsocketGroup($group);
        $this->pushEvent($this->getWebsocketMsg()->getFd(), 'group.left', ['group' => $group]);
    }
}
