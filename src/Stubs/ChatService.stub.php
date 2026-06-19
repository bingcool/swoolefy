<?php
namespace __APP_NAMESPACE__\Service;

use Swoolefy\Websocket\WebSocketService;

class ChatService extends WebSocketService
{
    /**
     * 小组广播 chat.message。
     *
     * 支持 msg_id 引用推送与 message 内联推送；enricher 内按是否有 msg_id 决定是否查库。
     */
    public function sendMessage(array $params)
    {
        $packet = $this->getWebsocketMsg();
        $group = (string) ($params['group'] ?? 'public');
        $message = (string) ($params['message'] ?? '');
        $msgId = trim((string) ($params['msg_id'] ?? ''));

        if ($msgId !== '') {
            // 引用模式：仅 push msg_id，完整内容由 MessagePushEnricher 在投递前加载
            $payload = [
                'group' => $group,
                'msg_id' => $msgId,
                'from_fd' => $packet->getFd(),
            ];
        } else {
            // 内联模式：直接携带 message 字符串
            $payload = [
                'group' => $group,
                'message' => $message,
                'from_fd' => $packet->getFd(),
            ];
        }

        $this->pushToGroup($group, 'chat.message', $payload);
    }

    /**
     * A→B 单聊：向 to_user_id 推送 chat.private。
     *
     * 依赖握手 query 的 uid 绑定用户身份（getWebsocketUserId）。
     * 支持 msg_id 引用模式与 message 内联模式，详见 sendMessage 注释。
     */
    public function sendPrivateMessage(array $params)
    {
        $toUserId = trim((string) ($params['to_user_id'] ?? $params['to_uid'] ?? ''));
        $message = (string) ($params['message'] ?? '');

        if ($toUserId === '') {
            throw new \InvalidArgumentException('to_user_id is required');
        }

        $fromUserId = $this->getWebsocketUserId();
        $msgId = trim((string) ($params['msg_id'] ?? ''));

        if ($msgId !== '') {
            // 引用模式：集群总线只传 msg_id，完整 message 由 push.enricher 在投递前查库组装
            $payload = [
                'msg_id' => $msgId,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
            ];
        } else {
            // 内联模式：不经过 enricher，直接推送完整 message 结构
            $payload = [
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'message' => [
                    'type' => 'text',
                    'msg_id' => uniqid(),
                    'msg' => $message,
                    'ts' => time(),
                ],
                'ts' => time(),
            ];
        }

        $count = $this->pushToUser($toUserId, 'chat.private', $payload);

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
