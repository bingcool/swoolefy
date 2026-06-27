<?php

namespace Swoolefy\Websocket\Offline;

/**
 * 用户离线消息持久化（**业务实现**，通常对接 MySQL 等 DB）。
 *
 * ## 与 Streams 的分工
 *
 * | 层级 | 保证范围 |
 * |------|----------|
 * | Redis Streams | 消费进程不丢（节点间 push 指令送达 Worker） |
 * | 本接口（离线表） | 用户不在线时消息不丢，上线后可补推/拉取 |
 *
 * ## 调用时机
 *
 * - **写入**：`pushToUser` / `ExternalPushPublisher::pushToUser` 命中 0 条连接
 * - **读取**：上线补推（`replay_on_reconnect`）或客户端/HTTP 主动拉取
 * - **ACK**：补推成功 / 客户端确认消费后 `markDelivered`
 *
 * ## 实现约定
 *
 * - `fetchPending` 只返回 status=pending，按 id 升序，支持 `afterId` 分页
 * - `data` 存 push 原始载荷（引用模式可只存 `{msg_id}`，补推时 enricher 仍会展开）
 * - `meta.trace_id` 用于补推时恢复链路日志
 *
 * @see OfflineMessageCoordinator
 * @see InMemoryOfflineMessageStore 单测 / 开发用内存实现
 */
interface OfflineMessageStoreInterface
{
    /**
     * 写入一条离线消息（用户当前无任何在线连接时由框架调用）。
     *
     * @param array $meta 扩展元数据；框架默认写入 trace_id、source、created_at
     *
     * @return string 业务侧消息主键（拉取分页 cursor / ACK 均依赖此 id）
     */
    public function store(string $userId, string $event, array $data, array $meta = []): string;

    /**
     * 拉取待投递离线消息。
     *
     * 返回结构每项须含：id, event, data, trace_id, created_at
     *
     * @return array<int, array{id:string,event:string,data:array,trace_id:string,created_at:int}>
     */
    public function fetchPending(string $userId, int $limit = 100, ?string $afterId = null): array;

    /**
     * 标记消息已投递。
     *
     * 触发场景：
     * - 上线补推成功（Coordinator 自动调用）
     * - 客户端拉取模式 ACK（`ackOfflineMessages` / HTTP）
     */
    public function markDelivered(string $userId, array $messageIds): int;

    /** 待投递条数（badge、监控、pullPending 的 pending_total） */
    public function countPending(string $userId): int;
}
