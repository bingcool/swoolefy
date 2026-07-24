# Swoolefy MQTT Broker

基于 Swoole `open_mqtt_protocol` + [simps/mqtt](https://github.com/simps/mqtt) 的生产级 MQTT Broker 封装。

## 目录结构

```
Mqtt/
├── MqttServer.php              # Swoole Server 入口，注册 connect/receive/close
├── MqttReceiveDispatcher.php   # V3/V5 报文分发、QoS2 状态机、CONNACK 拒绝
├── MqttShutdownCoordinator.php # 优雅停机共享标志 / drain
├── MqttSessionManager.php      # Worker 内会话/订阅/Retain/QoS 暂存
├── MqttSession.php             # 单连接会话快照
├── MqttTopicMatcher.php        # 主题通配符 + / # 匹配
├── MqttBrokerSupport.php       # publish 路由 Trait
├── MqttEventV3.php             # 3.1.1 事件基类（可继承）
├── MqttEventV5.php             # 5.0 事件基类
├── ProductionMqttEventV3.php   # 配置化鉴权 + 默认 bind/remove
├── ProductionMqttEventV5.php
├── conf.stub.php               # 应用配置模板
└── README.md
```

## 架构

```
Client TCP:1883
    └── MqttServer (Swoole Server)
            └── MqttReceiveDispatcher
                    ├── ProductionMqttEventV3 / V5 (可替换)
                    ├── MqttSessionManager (worker 内会话/订阅/Retain)
                    └── MqttTopicMatcher (+ / # 通配)
```

## 快速开始

```bash
composer require simps/mqtt
# 创建 mqtt 应用后启动 protocol/mqtt/MqttServer
```

`conf.php` 关键项：

```php
'mqtt' => [
    'protocol_level'     => MQTT_PROTOCOL_LEVEL3, // 或 MQTT_PROTOCOL_LEVEL5
    'auto_protocol'      => false,                // true 时按 CONNECT 自动识别
    'username'           => 'device',             // 空则不校验
    'password'           => 'secret',
    'mqtt_event_handler' => \Swoolefy\Mqtt\ProductionMqttEventV3::class,
],
```

## 生产特性

| 能力 | 说明 |
|------|------|
| 会话管理 | `client_id` ↔ `fd`、重复登录踢旧连接；状态为 Worker 内存 |
| 主题路由 | 按订阅 filter 匹配 publish（`+` / `#`），非全连接广播 |
| Retain | 保留消息；新 SUBSCRIBE 时下发匹配 retain |
| QoS | 入站 0/1 直发、2 四步握手；出站 QoS1/2 记 pending（停机 drain） |
| 鉴权失败 | 返回 CONNACK 原因码后再断开 |
| 心跳 | 建议 `heartbeat_check_interval` / `heartbeat_idle_time` |
| 优雅停机 | `graceful_shutdown`：停接 → 排在途 QoS → 断连 |

### 会话 / 重连语义（当前）

| 场景 | 行为 |
|------|------|
| 同 `client_id` 新连接 | 踢掉旧 fd，旧会话立即 `remove` |
| `clean_session=1` | 绑定前清本 fd 残留；断连后无残留 |
| `clean_session=0` | **仍无跨断连持久会话**（Worker 内存）；CONNACK `session_present=0` |
| 异常断连 Will | 尚未发送 Will 报文；CONNECT 时 retain will 可写入 Retain 表 |

持久会话 / Session Expiry 为后续增强；重连请按「新会话」处理订阅恢复。

### 优雅停机（`graceful_shutdown`）

`php cli.php stop` 或 `kill -15 MASTER`：

```
SIGTERM → Master 置 shutting_down + 停 accept
       → TCP connect / CONNECT 拒绝（V3 CONNACK=3 / V5 Server shutting down）
       → 已有连接：拒绝新 SUBSCRIBE/PUBLISH；放行 PING 与 QoS 完成（PUBREL/PUBACK/…）
       → WorkerStop：等待本 Worker pending QoS 清空或 drain_timeout → close fd
       → StopCmd 等待 ≥ drain_timeout + max_wait_time
```

```php
'graceful_shutdown' => [
    'enable' => true,
    'drain_timeout' => 30,
],
'setting' => [
    'max_wait_time' => 10,
],
```

## 自定义 Event Handler

继承 `MqttEventV3` 或 `ProductionMqttEventV3`，实现 `verify` / `connect` / `disconnect`：

```php
class DeviceMqttEvent extends ProductionMqttEventV3
{
    public function verify($username, $password): bool
    {
        return parent::verify($username, $password) && $this->checkDeviceToken($username);
    }
}
```

`subscribe` / `publish` / `unSubscribe` 已有默认 Broker 实现；若 override `subscribe()`，需 **返回 SUBACK granted codes 数组**。

## 多 Worker 说明

- 会话与订阅表为 **Worker 本地内存**（`MqttSessionManager`）。
- 建议 `dispatch_mode = 2`，同一 TCP 连接固定在同一 Worker。
- 跨 Worker 广播需自行扩展（Redis pub/sub、`pipeMessage` 等）。

## 测试

### 单元测试（无需启动 Broker）

```bash
composer test:mqtt
# 或
./vendor/bin/phpunit --testsuite unit --filter Mqtt
```

覆盖：

| 用例 | 说明 |
|------|------|
| topic matcher | 精确匹配、`+`/`#`、非法 `#` 位置 |
| session bind | client_id 踢旧连接、Clean Session |
| subscribe/match | V3 路由、V5 no_local |
| retain / will | Retain 存储与订阅下发 |
| QoS2 staging | 入站 PUBREC/PUBREL/PUBCOMP；出站 PUBREC→PUBREL→PUBCOMP |
| verify logic | 鉴权 hash_equals 行为 |
| graceful shutdown | 拒接标志、pending drain、重连踢旧 |

### 可选端到端冒烟

Broker 已启动且安装 simps/mqtt 时：

```bash
MQTT_SMOKE_HOST=127.0.0.1 MQTT_SMOKE_PORT=1883 ./vendor/bin/phpunit --testsuite unit --filter MqttModuleTest --group smoke
```

## 依赖

- `ext-swoole`（开启 MQTT 协议）
- `simps/mqtt`
