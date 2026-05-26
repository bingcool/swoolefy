# Nacos Support

| 层级 | 概念 | 核心作用 |
|---|---|---|
| Namespace | 环境隔离 | 隔离开发、测试、生产 |
| Group | 业务隔离 | 默认 `DEFAULT_GROUP` |
| Cluster | 物理/地域隔离 | 一般留空 |

## 配置文件

| 来源 | 内容 |
|---|---|
| 常量 `NACOS_FILE_PATH`（`cli.php` 可由环境变量 `NACOS_FILE_PATH` 注入，默认 `APP_PATH/nacos.yaml`） | Nacos **服务器连接** |
| `APP_PATH/application.yaml` | **应用行为**：`service_register`、`discovery_service_client`、`monitor_config_change` |

## NacosConfig

```php
$config = NacosConfig::load();
$client = $config->createClient();
```

## NacosServiceConfig

```php
$service = NacosServiceConfig::load();
```

## NacosFactory

```php
$envFile = NacosFactory::fetchConfigToEnv();
```

## ServiceRegister

```php
$registrar = ServiceRegister::create();
$registrar->register();
```

## DiscoveryClient

```php
$client = DiscoveryClient::create();
$instance = $client->choose();
```

## Monitor

见 `Monitor/README.md`。
