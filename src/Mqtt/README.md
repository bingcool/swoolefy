# Swoolefy MQTT Broker

基于 Swoole `open_mqtt_protocol` + [simps/mqtt](https://github.com/simps/mqtt) 的生产级 MQTT Broker 封装。

## 目录结构

```
Mqtt/
├── MqttServer.php              # Swoole Server 入口，注册 connect/receive/close
├── MqttReceiveDispatcher.php   # V3/V5 报文分发、QoS2 状态机、CONNACK 拒绝
├── MqttSessionManager.php      # Worker 内会话/订阅/Retain/QoS2 暂存
├── MqttSession.php             # 单连接会话快照
├── MqttTopicMatcher.php        # 主题通配符 + / # 匹配
├── MqttBrokerSupport.php       # publish 路由 Trait
├── MqttEventV3.php             # 3.1.1 事件基类（可继承）
├── MqttEventV5.php             # 5.0 事件基类
├── ProductionMqttEventV3.php   # 配置化鉴权 + 默认 bind/remove
├── ProductionMqttEventV5.php
├── conf.stub.php               # 应用配置模板
├── Tests/
│   ├── MqttModuleTest.php      # 综合单测入口
│   └── Support/MqttTestBootstrap.php
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
| 会话管理 | `client_id` ↔ `fd`、Clean Session、重复登录踢旧连接 |
| 主题路由 | 按订阅 filter 匹配 publish（`+` / `#`），非全连接广播 |
| Retain | 保留消息；新 SUBSCRIBE 时下发匹配 retain |
| QoS | 0/1 直发；2 四步握手（PUBREC → PUBREL → PUBCOMP） |
| 鉴权失败 | 返回 CONNACK 原因码后再断开 |
| 心跳 | 建议 `heartbeat_check_interval` / `heartbeat_idle_time` |

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
php src/Mqtt/Tests/MqttModuleTest.php
```

覆盖：

| 用例 | 说明 |
|------|------|
| topic matcher | 精确匹配、`+`/`#`、非法 `#` 位置 |
| session bind | client_id 踢旧连接、Clean Session |
| subscribe/match | V3 路由、V5 no_local |
| retain / will | Retain 存储与订阅下发 |
| QoS2 staging | PUBREC/PUBREL 暂存释放 |
| verify logic | 鉴权 hash_equals 行为 |

### 可选端到端冒烟

Broker 已启动且安装 simps/mqtt 时：

```bash
MQTT_SMOKE_HOST=127.0.0.1 MQTT_SMOKE_PORT=1883 php src/Mqtt/Tests/MqttModuleTest.php
```

## 依赖

- `ext-swoole`（开启 MQTT 协议）
- `simps/mqtt`
