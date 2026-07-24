<?php

namespace __APP_NAMESPACE__\Push;

use Swoolefy\Websocket\Push\PushPayloadEnricherInterface;

/**
 * 推送引用模式 — 业务侧载荷扩展器（脚手架示例）。
 *
 * ## 使用场景
 *
 * 1. HTTP/MQ 等业务进程将消息写入 DB，得到 msg_id
 * 2. 通过 pushToUser / ExternalPushPublisher 只推送 `{ "msg_id": "xxx" }`
 * 3. Redis 集群总线传输轻量引用
 * 4. WebSocket 节点投递前调用本类 enrich()，按 msg_id 查表组装完整 message
 * 5. 客户端收到完整 chat.private / chat.message 事件
 *
 * ## 配置（Config/websocket.php）
 *
 * ```php
 * 'push' => [
 *     'enricher' => [__APP_NAMESPACE__\Push\MessagePushEnricher::class, 'enrich'],
 * ],
 * ```
 *
 * ## 自定义
 *
 * 实现 `loadMessage()` 对接你的消息表；可按 $event / $fd 做权限过滤或字段裁剪。
 *
 * ## 协程安全（重要）
 *
 * Factory 每次投递会 new 本类；仍须保持**无请求态成员变量**。
 * 禁止 `$this->currentUser` / `$this->fd`；只用 enrich() 参数，或 FrameworkContext。
 *
 * @see PushPayloadEnricherInterface
 */
class MessagePushEnricher implements PushPayloadEnricherInterface
{
    /**
     * 将引用载荷扩展为客户端可消费的完整 data。
     *
     * @param string $event 如 chat.private、chat.message
     * @param array  $data  业务 push 的原始 data，引用模式通常含 msg_id
     * @param int    $fd    目标连接，可用于校验接收方是否有权查看该消息
     *
     * @return array|null 完整 data；消息不存在或无权查看时返回 null 跳过投递
     */
    public function enrich(string $event, array $data, int $fd): ?array
    {
        $msgId = trim((string) ($data['msg_id'] ?? ''));
        if ($msgId === '') {
            // 无 msg_id 时原样返回，内联完整载荷不经查库
            return $data;
        }

        $row = $this->loadMessage($msgId);
        if ($row === null) {
            // 消息已删除或不存在：不向该 fd 推送，避免客户端收到空消息
            return null;
        }

        // 合并业务 push 时附带的元数据（from_user_id 等）与 DB 中的消息体
        return array_merge($data, [
            'from_user_id' => (string) ($row['from_user_id'] ?? ($data['from_user_id'] ?? '')),
            'to_user_id' => (string) ($row['to_user_id'] ?? ($data['to_user_id'] ?? '')),
            'message' => [
                'type' => (string) ($row['type'] ?? 'text'),
                'msg_id' => $msgId,
                'msg' => (string) ($row['content'] ?? ''),
                'ts' => (int) ($row['created_at'] ?? time()),
            ],
        ]);
    }

    /**
     * 按 msg_id 从持久化存储加载消息行。
     *
     * 期望返回关联数组，键名示例：
     * - from_user_id, to_user_id, type, content, created_at
     *
     * @return array|null 查不到时返回 null
     */
    private function loadMessage(string $msgId): ?array
    {
        // TODO: 对接业务消息表，例如：
        // return ChatMessageRepository::findByMsgId($msgId);
        return null;
    }
}
