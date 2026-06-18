<?php
namespace __APP_NAMESPACE__\Service;

use Swoolefy\Core\BService;

class ChatService extends BService
{
    public function sendMessage(array $params)
    {
        $packet = $this->getWebsocketMsg();
        $room = (string) ($params['room'] ?? 'public');
        $message = (string) ($params['message'] ?? '');

        // room 内广播，适配普通 WebSocket 统一格式；Socket.IO 客户端会通过 ack 获得调用结果。
        $this->pushToRoom($room, 'chat.message', [
            'room' => $room,
            'message' => $message,
            'from_fd' => $packet->getFd(),
        ]);
    }

    public function joinRoom(array $params)
    {
        $room = (string) ($params['room'] ?? 'public');
        $this->joinWebsocketRoom($room);
        $this->pushEvent($this->getWebsocketMsg()->getFd(), 'room.joined', ['room' => $room]);
    }

    public function leaveRoom(array $params)
    {
        $room = (string) ($params['room'] ?? 'public');
        $this->leaveWebsocketRoom($room);
        $this->pushEvent($this->getWebsocketMsg()->getFd(), 'room.left', ['room' => $room]);
    }
}
