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
