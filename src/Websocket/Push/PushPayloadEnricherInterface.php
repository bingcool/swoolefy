<?php

namespace Swoolefy\Websocket\Push;

/**
 * 推送投递前载荷扩展器（引用模式 / 自定义消费处理）。
 *
 * ## 背景
 *
 * 生产环境常见做法：HTTP / MQ / 定时任务等业务进程先把消息写入 DB，再通过 Redis Pub/Sub
 * 或集群总线只推送轻量引用（如 `{ "msg_id": "m-1001" }`），避免大 payload 在总线上重复传输。
 *
 * WebSocket 节点在真正执行 `server->push()` **之前**，调用本接口将引用解析为客户端所需的完整 data。
 *
 * ## 调用链路
 *
 * ```
 * pushToUser / pushToGroup / broadcast / ExternalPushPublisher
 *   → ClusterPushBus（Redis 扇出，data 可为引用）
 *   → PushDeliveryHandler / LocalPushDispatcher
 *   → WebsocketConnectionManager::deliverEventToFdLocally()
 *   → PushPayloadResolver::resolve()          ← 调用 enricher（若已配置）
 *   → PushPayloadEnricherInterface::enrich()  ← 业务自定义查库/缓存
 *   → server->push(完整 data)
 * ```
 *
 * ## 配置
 *
 * 在 `Config/websocket.php` 顶层 `push.enricher` 注册实现类或 callable，详见 PushPayloadEnricherFactory。
 *
 * ## 注意
 *
 * - `enrich()` 按 **目标 fd** 调用，同一 push 指令推给 N 个连接会执行 N 次；热点消息建议在 enricher 内做进程级缓存。
 * - 返回 `null` 表示跳过该 fd 的投递（如消息已撤回/删除），不会向客户端发送任何帧。
 *
 * @see PushPayloadResolver
 * @see PushPayloadEnricherFactory
 */
interface PushPayloadEnricherInterface
{
    /**
     * 将业务 push 的原始 data 扩展为投递给客户端的最终载荷。
     *
     * @param string $event Socket.IO / WS 事件名，如 chat.private、chat.message
     * @param array  $data  业务侧 push 时传入的 data（引用模式可能仅含 msg_id、from_user_id 等）
     * @param int    $fd    本次投递的目标连接 fd，可用于按连接做权限过滤或个性化字段
     *
     * @return array|null 完整 data 数组；null 表示跳过本次投递
     */
    public function enrich(string $event, array $data, int $fd): ?array;
}
