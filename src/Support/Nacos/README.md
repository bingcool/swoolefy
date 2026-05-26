# Nacos Support

| 层级 | 概念 | 核心作用 |
|---|---|---|
| Namespace | 环境隔离 | 隔离开发、测试、生产 |
| Group | 业务隔离 | 默认 `DEFAULT_GROUP` |
| Cluster | 物理/地域隔离 | 一般留空 |

## 配置文件

| 文件                                                     | 内容 |
|--------------------------------------------------------|---|
| `nacos.yaml`（`nacosFilePath` 指定完整路径，可与 app 目录分离,多项目共用） | Nacos **服务器连接**（host、port、data_id 等） |
| `APP_PATH/application.yaml`                            | **应用行为**：`service_register`、`discovery_service_client`、`monitor_config_change` |

## NacosConfig

仅读取 `nacos.yaml`（服务器连接）。`application.yaml` 由 `appPath` 传入，经 `NacosServiceConfig` 等读取。

```php
$config = NacosConfig::load(APP_PATH . '/nacos.yaml');
// 或多项目共用：NacosConfig::load('/etc/nacos/shared.yaml');
$client = $config->createClient($logger);
```

## NacosServiceConfig

读取 `application.yaml` → `nacos.service_register`（本机注册信息）。

```php
$service = NacosServiceConfig::load();
```

## NacosFactory

一次性从 Nacos 拉取配置并写入 `APP_PATH/.env`（Guzzle 直连，不依赖 Log；内容须为合法 .env 格式）：

```php
use Swoolefy\Support\Nacos\NacosFactory;

$envFile = NacosFactory::fetchConfigToEnv(APP_PATH . '/nacos.yaml');
```

## ServiceRegister

依赖 `NacosConfig`（连接）+ `NacosServiceConfig`（注册参数）。

```php
$registrar = ServiceRegister::create($appPath, $nacosFilePath, $logger);
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
