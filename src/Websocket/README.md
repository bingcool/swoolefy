# Swoolefy WebSocket 使用指南

本文档说明 `Swoolefy\Websocket` 模块的完整用法，涵盖应用创建、配置、路由、业务开发、推送、鉴权、Socket.IO 与多机集群部署。

---

## 一、能力概览

| 能力 | 说明 |
|------|------|
| 原生 WebSocket | 统一 JSON 消息协议，路由到 `Router/service.php` |
| Socket.IO v4 | 仅支持 **websocket transport**（不支持 long-polling） |
| 连接管理 | 本地 `Swoole\Table` 管理 fd / user / room |
| 业务基类 | `WebSocketService` 提供 push / 房间 / 广播等 API |
| 多机集群 | Redis 全局索引 + 按 `server_id` 频道 Pub/Sub 扇出推送 |
| 鉴权 | 握手阶段 token 校验，支持静态 token 或 callback |
| 心跳清理 | 框架级空闲连接超时断开 |
| 分片帧重组 | 自动合并 `finish=false` 的 WebSocket 分片（RFC 6455） |

### 架构简图

```
客户端
  │
  ▼
WebsocketServer::onMessage
  │
  ├─ WebsocketFrameAssembler（分片帧重组）
  │
  ├─ 原生 WebSocket JSON ──► WebsocketHandler ──► Router/service.php ──► WebSocketService
  │
  └─ Socket.IO (ws transport) ──► SocketIOHandler ──► event_routes ──► Router/service.php ──► WebSocketService
                                        │
                                        ▼
                              WebsocketConnectionManager（本地 Table）
                                        │
                          cluster.enable=true 时双写 Redis 全局索引
                                        │
                          推送 ──► PushDispatcherFactory ──► 本机直推 / Redis Pub/Sub
```

---

## 二、快速开始

### 2.1 创建应用

```bash
cd swoolefy
php cli.php create WebsocketService
```

创建后自动生成：

```
WebsocketService/
├── Config/
│   ├── websocket.php      # WebSocket + 集群配置
│   └── socketio.php       # Socket.IO 事件路由
├── Router/
│   └── service.php        # endpoint 路由表
├── Service/
│   ├── DemoService.php    # 原生 WebSocket 示例
│   └── ChatService.php    # 房间/聊天示例
├── Tests/
│   └── socketio-client.html
└── WebsocketEventServer.php
```

### 2.2 注册端口（`cli.php`）

```php
'WebsocketService' => [
    'protocol' => 'websocket',
    'port'     => 9508,
],
```

### 2.3 启动服务

```bash
SWOOLEFY_CLI_ENV=dev php cli.php start WebsocketService
```

### 2.4 运行测试

```bash
# 冒烟测试（需先启动服务）
SWOOLEFY_CLI_ENV=dev php WebsocketService/Tests/WebsocketSmokeTest.php

# 分片帧单元测试
php WebsocketService/Tests/WebsocketFrameAssemblerTest.php

# 集群单元测试
SWOOLEFY_CLI_ENV=dev php WebsocketService/Tests/WebsocketClusterTest.php

# 浏览器测试 Socket.IO
open WebsocketService/Tests/socketio-client.html
```

---

## 三、目录说明

```
src/Websocket/
├── WebSocketService.php          # 业务基类（推送、房间、获取消息包）
├── WebsocketServer.php           # Swoole WebSocket Server 封装
├── WebsocketEventServer.php      # 应用继承的事件服务基类
├── WebsocketHandler.php          # 原生 WebSocket 消息解析与路由
├── WebsocketPacket.php           # 统一消息包
├── WebsocketResponse.php         # 统一响应格式
├── WebsocketConnectionManager.php # 连接表、房间、推送分发入口
├── WebsocketFrameAssembler.php    # WebSocket 分片帧重组
├── WebsocketAuthenticator.php    # 握手鉴权
├── SocketIO/                     # Socket.IO 协议实现
│   ├── SocketIOHandler.php
│   ├── SocketIOPacket.php
│   └── SocketIOClient.php        # PHP 协程客户端
└── Cluster/                      # 多机水平扩展
    ├── ClusterPushBus.php        # Redis 扇出总线（Worker / 外部进程共用）
    ├── ExternalPushPublisher.php # HTTP/CLI 外部推送入口
    ├── ClusterPushDispatcher.php
    ├── RedisConnectionRegistry.php
    ├── WebsocketPushSubscriberProcess.php
    └── ...
```

---

## 四、配置

配置拆分为三个文件，职责清晰：

| 文件 | 职责 |
|------|------|
| `Protocol/conf.php` | Swoole 进程、端口、worker 数等 |
| `Config/websocket.php` | 连接表、心跳、鉴权、集群 |
| `Config/socketio.php` | Socket.IO 开关、心跳、事件路由 |

`Protocol/conf.php` 中通过 `array_merge` 加载：

```php
'websocket' => array_merge(
    \Swoolefy\Core\SystemEnv::loadWebsocketConf(),
    ['socketio' => \Swoolefy\Core\SystemEnv::loadSocketIoConf()]
),
```

### 4.1 `Config/websocket.php`

```php
return [
    // 连接表容量（按最大并发连接数调整）
    'connection_table_size' => 65536,
    'index_table_size'      => 131072,

    // 心跳：客户端需定期发 ping 或业务消息，否则被断开
    'heartbeat_check_interval' => 30,  // 扫描间隔（秒）
    'heartbeat_idle_time'      => 90,  // 空闲超时（秒）

    // 分片帧重组后单条消息最大字节数；0 表示使用 Protocol/conf.php 的 package_max_length
    'max_fragment_payload'     => 0,

    // 握手鉴权
    'auth' => [
        'enable' => false,
        'tokens' => ['dev-token'],
        'callback' => null,  // 推荐生产使用 callback 校验 JWT
    ],

    // 多机集群（详见第七节）
    'cluster' => [
        'enable' => false,
        'server_id' => '',   // 生产必配，每实例唯一
        // ...
    ],
];
```

### 4.2 `Config/socketio.php`

```php
return [
    'enable' => true,
    'ping_interval' => 25,
    'ping_timeout'  => 20,
    'max_payload'   => 1000000,

    // Socket.IO emit 事件名 → Router/service.php endpoint
    'event_routes' => [
        'chat.send'   => 'Service/Chat/Send',
        'room.join'   => 'Service/Chat/JoinRoom',
        'room.leave'  => 'Service/Chat/LeaveRoom',
    ],
];
```

未配置 `event_routes` 时，默认约定：`chat.send` → `chat/send`（需在 `Router/service.php` 中注册）。

### 4.3 分片帧（Fragment）配置

WebSocket 协议允许一条消息拆成多个帧发送（`finish=false` 的首帧 + `CONTINUATION` 续帧）。框架在 `WebsocketServer` 入口通过 `WebsocketFrameAssembler` 自动重组，**业务层和 Service 无需感知分片**。

| 配置项 | 说明 |
|--------|------|
| `max_fragment_payload` | 重组后单条消息最大字节数；`0` 时沿用 `package_max_length`（默认 2MB） |

行为说明：

- 每个分片到达时刷新连接心跳（`touch`），避免长消息传输中被误判超时
- 连接 `close` 时自动清理该 fd 的分片缓存
- 协议错误（如孤立续帧、超长 payload）以关闭码 **1009** 断开

---

## 五、路由与业务开发

### 5.1 注册路由 `Router/service.php`

```php
return [
    'Service/Demo/Ping' => [
        'dispatch_route' => [WebsocketService\Service\DemoService::class, 'ping'],
    ],
    'Service/Chat/Send' => [
        'dispatch_route' => [WebsocketService\Service\ChatService::class, 'sendMessage'],
    ],
];
```

endpoint 格式：`Service/{模块}/{动作}`，与 HTTP 路由风格一致。

### 5.2 编写 Service

业务 Service **必须继承** `Swoolefy\Websocket\WebSocketService`（不要直接继承 `BService`）：

```php
namespace WebsocketService\Service;

use Swoolefy\Websocket\WebSocketService;

class ChatService extends WebSocketService
{
    public function sendMessage(array $params)
    {
        $packet = $this->getWebsocketMsg();
        $room = (string) ($params['room'] ?? 'public');

        $this->pushToRoom($room, 'chat.message', [
            'room'    => $room,
            'message' => (string) ($params['message'] ?? ''),
            'from_fd' => $packet->getFd(),
        ]);
    }

    public function joinRoom(array $params)
    {
        $room = (string) ($params['room'] ?? 'public');
        $this->joinWebsocketRoom($room);
        $this->pushEvent($this->getWebsocketMsg()->getFd(), 'room.joined', ['room' => $room]);
    }
}
```

### 5.3 `WebSocketService` API

| 方法 | 说明 |
|------|------|
| `getWebsocketMsg()` | 获取当前消息包（fd、request_id、endpoint 等） |
| `pushRaw($fd, $payload)` | 推送原始字符串 |
| `push($fd, $dto)` | 推送 `BaseResponseDto` |
| `pushEvent($fd, $event, $data)` | 向指定连接推送事件 |
| `pushToUser($userId, $event, $data)` | 向用户所有连接推送 |
| `pushToRoom($room, $event, $data)` | 向房间广播 |
| `broadcast($event, $data)` | 全服广播 |
| `joinWebsocketRoom($room)` | 当前连接加入房间 |
| `leaveWebsocketRoom($room)` | 当前连接离开房间 |

`cluster.enable=true` 时，`pushToUser` / `pushToRoom` / `broadcast` 自动跨节点推送，业务代码无需修改。

---

## 六、客户端协议

### 6.1 原生 WebSocket

#### 请求格式（推荐）

```json
{
  "type": "request",
  "event": "Service/Demo/ReportMsg",
  "request_id": "req-001",
  "data": { "msg": "hello" }
}
```

#### 心跳

```json
{
  "type": "ping",
  "event": "Service/Demo/Ping",
  "request_id": "ping-001",
  "data": {}
}
```

服务端返回 `type: "pong"`。

#### 响应格式

```json
{
  "type": "response",
  "request_id": "req-001",
  "event": "Service/Demo/ReportMsg",
  "code": 0,
  "msg": "ok",
  "trace_id": "...",
  "data": { "echo": "hello" }
}
```

#### 服务端推送事件

```json
{
  "type": "event",
  "event": "chat.message",
  "code": 0,
  "msg": "ok",
  "data": { "room": "public", "message": "hi" }
}
```

#### 兼容旧格式

```
Service/Demo/ReportMsg::{"msg":"hello"}
```

#### JavaScript 示例

```javascript
const ws = new WebSocket('ws://127.0.0.1:9508/?uid=10001');

ws.onopen = () => {
  ws.send(JSON.stringify({
    type: 'request',
    event: 'Service/Chat/Send',
    request_id: '1',
    data: { room: 'public', message: 'hello' }
  }));
};

ws.onmessage = (e) => {
  const msg = JSON.parse(e.data);
  if (msg.type === 'event' && msg.event === 'chat.message') {
    console.log('收到房间消息', msg.data);
  }
};
```

### 6.2 Socket.IO

#### 限制

- 仅支持 **Engine.IO v4 + websocket transport**
- **不支持** long-polling（客户端必须配置 `transports: ['websocket']`）
- 默认仅支持 `/` 命名空间

#### 事件路由链

```
socket.emit('room.join', { room: 'public' })
  → Config/socketio.php event_routes
  → Router/service.php Service/Chat/JoinRoom
  → ChatService::joinRoom()
```

#### JavaScript 示例

```html
<script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
<script>
const socket = io('http://127.0.0.1:9508', {
  transports: ['websocket'],  // 必须
  path: '/socket.io/',
  query: { uid: '10001', token: 'dev-token' }
});

socket.on('connect', () => console.log('connected', socket.id));

socket.emit('room.join', { room: 'public' }, (ack) => {
  console.log('ack', ack);  // [{ code: 0, msg: 'ok' }]
});

socket.on('chat.message', (data) => {
  console.log('chat.message', data);
});

socket.emit('chat.send', { room: 'public', message: 'hello' });
</script>
```

完整交互测试页：`{App}/Tests/socketio-client.html`

#### PHP 协程客户端

```php
use Swoolefy\Websocket\SocketIO\SocketIOClient;

$client = new SocketIOClient('127.0.0.1', 9508);
$client->connect(['uid' => 'php-user']);

$ack = $client->emitWithAck('room.join', [['room' => 'public']], 3);
$client->emitWithAck('chat.send', [['room' => 'public', 'message' => 'hi']], 3);

$client->close();
```

---

## 七、鉴权

在 `Config/websocket.php` 中开启：

```php
'auth' => [
    'enable' => true,
    'tokens' => ['dev-token'],
    // 或使用 callback（推荐生产环境）
    'callback' => function (\Swoole\Http\Request $request, string $token) {
        if ($token !== 'valid-jwt') {
            return false;
        }
        return ['user_id' => (string) ($request->get['uid'] ?? '0')];
    },
],
```

### Token 提取优先级

1. `Authorization: Bearer {token}`
2. Query：`token` / `access_token`
3. Header：`Sec-WebSocket-Protocol`

### 用户 ID

- 默认从 query 的 `uid` / `user_id` 获取
- 鉴权 callback 可返回 `user_id` 覆盖
- 用于 `pushToUser` 和连接索引绑定

鉴权失败时，服务端以 WebSocket 关闭码 **1008** 断开连接。

---

## 八、多机集群部署

### 8.1 启用

```php
// Config/websocket.php
'cluster' => [
    'enable'    => true,
    'server_id' => 'ws-prod-01',   // 每个实例唯一！
    'redis' => [
        'key_prefix' => 'ws:WebsocketService:',
        // host/port 默认读 Config/dc.php
    ],
    'push' => [
        'channel_prefix' => 'ws:push:WebsocketService:',
    ],
    'conn_ttl'         => 180,
    'cleanup_interval' => 30,
    'on_redis_failure' => 'reject_open',  // 或 local_only
],
```

### 8.2 工作原理

| 层级 | 职责 |
|------|------|
| 本地 `Swoole\Table` | 管理本节点 fd，执行 `server->push()` |
| Redis 全局索引 | `conn_id = {server_id}:{fd}`，跨节点 user/room 查询 |
| Redis Pub/Sub | 频道 `ws:push:{app}:{server_id}`，精准扇出到目标节点 |
| 订阅进程 | 每节点 1 个 `WebsocketPushSubscriberProcess` |

### 8.3 部署清单

1. 每个实例配置唯一 `server_id`
2. 所有实例共用同一 Redis
3. Nginx 建连配置 **sticky session**（ip_hash）
4. 推送不依赖 sticky，走 Redis 总线

```nginx
upstream ws_backend {
    ip_hash;
    server 10.0.0.1:9508;
    server 10.0.0.2:9508;
}

location / {
    proxy_pass http://ws_backend;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
```

### 8.4 环境变量示例

```bash
# 实例 1
WS_SERVER_ID=ws-prod-01 SWOOLEFY_CLI_ENV=prod php cli.php start WebsocketService

# 实例 2
WS_SERVER_ID=ws-prod-02 SWOOLEFY_CLI_ENV=prod php cli.php start WebsocketService
```

在 `Config/websocket.php` 中读取：`env('WS_SERVER_ID')`

### 8.5 外部进程推送（HTTP / CLI / 队列）

业务在 WebSocket Worker 外（PHP-FPM、独立脚本、队列消费者）触发推送时，使用 `ExternalPushPublisher`，**仅走 Redis 发布**，不依赖 `Swfy::getServer()`：

```php
use Swoolefy\Websocket\Cluster\ExternalPushPublisher;

// 定义 APP_NAME、APP_PATH 后可直接调用
define('APP_NAME', 'WebsocketService');
define('APP_PATH', '/path/to/WebsocketService');

ExternalPushPublisher::pushToRoom('demo', 'chat.message', ['msg' => 'hi']);
ExternalPushPublisher::pushToUser('user-1', 'notify', ['title' => 'new']);
ExternalPushPublisher::broadcast('system.announce', ['text' => 'maintenance']);
```

要求：

1. `cluster.enable=true`
2. 各 WebSocket 节点 `WebsocketPushSubscriberProcess` 正常运行
3. 外部进程与 WebSocket 服务共用同一 Redis 与 `key_prefix` / `channel_prefix`

示例脚本：

```bash
php WebsocketService/Scripts/push_external.php demo chat.message "hello from http"
```

---

## 九、消息流转说明

### 9.1 分片帧重组

```
客户端发送多帧消息（finish=false ... finish=true）
  → WebsocketServer::onMessage
  → WebsocketFrameAssembler::feed
       ├─ 未收齐：返回 null，等待下一帧
       └─ 已收齐：返回完整 Frame
  → 原生 WS / Socket.IO 业务处理
```

### 9.2 原生 WebSocket 一次请求

```
客户端 send JSON
  → WebsocketFrameAssembler（若为多帧则先重组）
  → WebsocketEventServer::onMessage
  → WebsocketHandler::run
  → WebsocketPacket::parse
  → ServiceDispatch（Router/service.php）
  → DemoService::reportMsg
  → pushRaw / pushEvent 响应
```

### 9.3 Socket.IO 一次 emit

```
客户端 emit('chat.send', data)
  → WebsocketFrameAssembler（若为多帧则先重组）
  → SocketIOHandler::onMessage
  → SocketIOPacket::parse
  → event_routes 映射 endpoint
  → WebsocketHandler::handlePacket
  → ChatService::sendMessage
  → pushToRoom（集群模式下走 Redis 扇出）
  → 返回 ack: [{ code: 0, msg: 'ok' }]
```

### 9.4 跨节点 pushToRoom

```
Node A: ChatService::pushToRoom('public', 'chat.message', $data)
  → Redis SMEMBERS room:public → [conn_id...]
  → 按 server_id 分组
  → 本节点 conn：直接 deliver
  → 远端 conn：PUBLISH ws:push:app:ws-prod-02
  → Node B 订阅进程收到 → PushDeliveryHandler → server->push(fd)
```

---

## 十、应用事件扩展

继承 `WebsocketEventServer` 实现生命周期钩子：

```php
namespace WebsocketService;

class WebsocketEventServer extends \Swoolefy\Websocket\WebsocketEventServer
{
    public function onOpen(\Swoole\WebSocket\Server $server, \Swoole\Http\Request $request)
    {
        // 连接建立后（框架已完成鉴权与连接登记）
    }

    public function onClose(\Swoole\WebSocket\Server $server, int $fd)
    {
        // 连接关闭后
    }

    public function onWorkerStart(\Swoole\WebSocket\Server $server, int $worker_id) {}
    public function onFinish(\Swoole\WebSocket\Server $server, int $task_id, $data) {}
    public function onPipeMessage(\Swoole\WebSocket\Server $server, int $from_worker_id, $message) {}
    public function onMessageFromBinary(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame) {}
    public function onMessageFromClose(\Swoole\WebSocket\Server $server, $frame) {}
}
```

---

## 十一、常见问题

### Q: Socket.IO 连接失败，提示 polling 不支持？

客户端必须设置 `transports: ['websocket']`，当前实现不支持 long-polling 降级。

### Q: pushToRoom 只有部分用户收到？

- 单机：确认目标连接已 `joinWebsocketRoom`
- 集群：确认 `cluster.enable=true`、Redis 可用、各实例 `server_id` 唯一

### Q: 集群模式下 Redis 挂了怎么办？

- `on_redis_failure=reject_open`：拒绝新连接（推荐）
- `on_redis_failure=local_only`：降级为单机模式，推送不跨节点

### Q: 原生 WebSocket 和 Socket.IO 能否共存？

可以。同一服务同时支持两种协议，框架根据握手路径和连接标记 `is_socketio` 自动区分。

### Q: 大消息分片发送会被断开吗？

不会。框架已支持 RFC 6455 分片帧自动重组；只要重组后消息不超过 `max_fragment_payload`（或 `package_max_length`）即可。若收到孤立续帧或超长消息，会以关闭码 1009 断开。

### Q: 如何从 HTTP 接口触发 WebSocket 推送？

启用集群后，在 HTTP/CLI 等外部进程中调用 `ExternalPushPublisher`（见 §8.5），由 Redis Pub/Sub 扇出到各 WebSocket 节点投递。若在 WebSocket Worker 内推送，直接使用 `WebSocketService::pushToRoom` 等方法即可。

---

## 十二、相关文件索引

| 场景 | 文件 |
|------|------|
| 创建应用模板 | `src/Cmd/CreateCmd.php` |
| 配置模板 | `src/Stubs/websocket.conf.stub.php`、`socketio.conf.stub.php` |
| 示例应用 | `WebsocketService/` |
| 冒烟测试 | `WebsocketService/Tests/WebsocketSmokeTest.php` |
| 分片帧测试 | `WebsocketService/Tests/WebsocketFrameAssemblerTest.php` |
| 集群测试 | `WebsocketService/Tests/WebsocketClusterTest.php` |
| 浏览器测试 | `WebsocketService/Tests/socketio-client.html` |
| Chat 示例 | `src/Stubs/ChatService.stub.php` |

---

## 十三、推荐实践

1. 业务 Service 统一继承 `WebSocketService`
2. Socket.IO 事件路由维护在 `Config/socketio.php`，不散落在代码中
3. 生产环境开启 `auth.callback`，不要仅用静态 token 列表
4. 多机部署必须配置 `server_id`，并启用 Redis 高可用（Sentinel）
5. 房间广播前确保客户端已 `joinRoom`
6. 大房间（万人级）考虑分层频道或专用方案，避免全量 SMEMBERS
7. 客户端发送超大 JSON 时依赖浏览器/WebSocket 库自动分片即可，服务端会自动重组
