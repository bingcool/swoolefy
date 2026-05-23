# Nacos Support

| 层级 | 概念 | 核心作用 |
|---|---|---|
| Namespace | 环境隔离 | 隔离开发、测试、生产 |
| Group | 业务隔离 | 默认 `DEFAULT_GROUP` |
| Cluster | 物理/地域隔离 | 一般留空 |

## 配置文件

| 文件 | 内容 |
|---|---|
| `APP_PATH/nacos.yaml` | Nacos **服务器连接**（host、port、data_id 等） |
| `APP_PATH/application.yaml` | **应用行为**：`service_register`、`discovery_service_client`、`monitor_config_change` |

## NacosConfig

```php
$config = NacosConfig::load();
$client = $config->createClient($logger);
```

## ServiceRegister

读取 `application.yaml` → `nacos.service_register`。

```php
$registrar = new ServiceRegister(NacosConfig::load(), $logger);
$registrar->register(); // 使用配置中的 ip/port/service_name
```

## DiscoveryClient

读取 `application.yaml` → `nacos.discovery_service_client`。

```php
$client = DiscoveryClient::create(null, null, null, $logger);
$instance = $client->choose();
```

负载均衡：`random` | `round_robin` | `weight`

## Monitor

读取 `application.yaml` → `nacos.monitor_config_change`，见 `Monitor/README.md`。
