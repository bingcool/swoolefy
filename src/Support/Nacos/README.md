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
| `APP_PATH/application.yaml` | **应用行为**：`service_config`、`service_register`、`discovery_service_client`、`monitor_config_change` |

配置优先级：**YAML 文件中的非空值 > 同名环境变量 > 代码默认值**。

<a id="env-vars"></a>

## 环境变量

以下环境变量可在 Docker / K8s / CI 中覆盖 YAML 配置，便于不同环境部署。

### 全局

| 环境变量 | 对应配置 | 说明                                                                        | 默认值                                |
|:---|:---|:--------------------------------------------------------------------------|:-----------------------------------|
| `NACOS_FILE_PATH` | — | `nacos.yaml` 完整路径，在 `cli.php` 中注入为常量                                      | `APP_PATH/nacos.yaml`              |
| `LOCAL_NACOS_SERVICE_AUTO_SWITCH` | — | `本地开发环境启用，可以自动切换到dev已部署服务，方便调用各个服务调试，无需都在本地启用依赖的服务。注意：本地环境要能访问dev服务注册的IP` | LOCAL_NACOS_SERVICE_AUTO_SWITCH=1  |

### 服务器连接（`nacos.yaml` → `NacosConfig`）

| 环境变量 | YAML 键 | 说明 | 默认值 |
|:---|:---|:---|:---|
| `NACOS_HOST` | `nacos.host` | Nacos 服务器地址 | `127.0.0.1` |
| `NACOS_PORT` | `nacos.port` | Nacos 服务器端口 | `8848` |
| `NACOS_USERNAME` | `nacos.username` | 登录用户名 | 空 |
| `NACOS_PASSWORD` | `nacos.password` | 登录密码 | 空 |
| `NACOS_AUTHORIZATION_BEARER` | `nacos.authorization_bearer` | 是否使用 Bearer 鉴权 | `false` |

### 配置中心（`application.yaml` → `nacos.service_config` → `ServiceConfig`）

| YAML 键 | 说明 | 是否必填 |
|:---|:---|:---|
| `nacos.service_config.data_id` | 配置中心 dataId（按项目区分） | 是 |
| `nacos.service_config.group` | 配置中心 group | 是 |
| `nacos.service_config.tenant` | 命名空间 tenant | 否 |

`data_id` 与 `group` 仅读取 `application.yaml`，不支持环境变量覆盖，且不能为空。

### 服务注册（`application.yaml` → `nacos.service_register` → `NacosServiceConfig`）

| 环境变量 | YAML 键 | 说明 | 默认值 |
|:---|:---|:---|:---|
| `NACOS_SERVICE_REGISTER_HOST` | `nacos.service_register.ip` | 注册到 Nacos 的本机 IP | 空（自动探测本机 IP） |
| `NACOS_SERVICE_REGISTER_PORT` | `nacos.service_register.port` | 注册到 Nacos 的本机端口 | `0`（回退 `WORKER_PORT` 或服务 `port`） |
| `NACOS_SERVICE_NAME` | `nacos.service_register.service_name` | 服务名 | 空 |
| `NACOS_SERVICE_NAMESPACE_ID` | `nacos.service_register.namespace_id` | 命名空间 ID | 空 |
| `NACOS_SERVICE_GROUP_NAME` | `nacos.service_register.group_name` | 分组名 | 空 |
| `NACOS_SERVICE_WEIGHT` | `nacos.service_register.weight` | 实例权重 | `1` |
| `NACOS_SERVICE_EPHEMERAL` | `nacos.service_register.ephemeral` | 是否临时实例 | `true` |
| `NACOS_SERVICE_HEARTBEAT_INTERVAL` | `nacos.service_register.heartbeat_interval` | 心跳间隔（秒） | `10` |

内置注册进程 `NacosRegisterServiceProcess` 在 `application.yaml` 中 `enable_nacos_register: true` 时自动启动；`NACOS_SERVICE_REGISTER_HOST` / `NACOS_SERVICE_REGISTER_PORT` 会写入启动日志，便于排查。

`port` 未配置时的回退顺序：`NACOS_SERVICE_REGISTER_PORT` → YAML `port` → `WORKER_PORT`（`cli.php`）→ 服务配置 `port`。

### 服务发现（`application.yaml` → `nacos.discovery_service_client` → `DiscoveryConfig`）

| 环境变量 | YAML 键 | 说明 | 默认值 |
|:---|:---|:---|:---|
| `NACOS_DISCOVERY_CACHE_TTL` | `nacos.discovery_service_client.cache_ttl` | 实例列表缓存 TTL（秒） | `60` |
| `NACOS_DISCOVERY_LOAD_BALANCER` | `nacos.discovery_service_client.load_balancer` | 负载均衡策略 | `random` |
| `NACOS_DISCOVERY_HEALTHY_ONLY` | `nacos.discovery_service_client.healthy_only` | 仅选健康实例 | `true` |
| `NACOS_DISCOVERY_CLUSTERS` | `nacos.discovery_service_client.clusters` | 集群名 | 空 |

`load_balancer` 可选值：`random` | `round_robin` | `weight`。

### 配置变更监听（`application.yaml` → `nacos.monitor_config_change` → `MonitorConfig`）

详见 [Monitor/README.md](Monitor/README.md)。

| 环境变量 | YAML 键 | 说明 | 默认值 |
|:---|:---|:---|:---|
| `NACOS_ENV_FILE` | `nacos.monitor_config_change.env_file` | Nacos 配置写入的 `.env` 路径 | `APP_PATH/.env` |
| `NACOS_RELOAD_LOCK` | `nacos.monitor_config_change.lock_file` | 重启防重入锁文件 | `/tmp/swoolefy_{app}_nacos_restart.lock` |
| `NACOS_LISTENER_TIMEOUT_MS` | `nacos.monitor_config_change.listener_timeout_ms` | 长轮询超时（毫秒） | `30000` |
| `NACOS_LISTENER_FAILED_MS` | `nacos.monitor_config_change.failed_wait_ms` | 监听失败后等待（毫秒） | `3000` |

### 示例

```bash
# nacos.yaml 路径
export NACOS_FILE_PATH=/app/config/nacos.yaml

# Nacos 服务器
export NACOS_HOST=192.168.1.102
export NACOS_PORT=8848

# 服务注册（Docker 宿主机映射场景常用）
export NACOS_SERVICE_REGISTER_HOST=192.168.1.103
export NACOS_SERVICE_REGISTER_PORT=9501
export NACOS_SERVICE_NAME=my-service
```

## NacosConfig

```php
$config = NacosConfig::load();
$client = $config->createClient();
```

## ServiceConfig

```php
$config = ServiceConfig::load();
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

框架内置 `NacosRegisterServiceProcess` 会在 Swoole 服务启动就绪后自动注册并心跳；也可在自定义进程中调用 `ServiceRegister`。

## DiscoveryClient

```php
$client = DiscoveryClient::create();
$instance = $client->choose();
```

## Monitor

见 [Monitor/README.md](Monitor/README.md)。
