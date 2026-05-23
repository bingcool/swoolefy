# Nacos Support

| 层级 | 概念 | 核心作用 | 典型场景                                               |
|---|---|---|----------------------------------------------------|
| 最高层 | Namespace | 环境隔离 | 隔离开发、测试、生产等不同环境                                    |
| 中层 | Group (分组) | 业务/逻辑隔离 | 隔离不同业务线（如订单组、支付组)（一般都设置同一个组，默认都会分配到DEFAULT_GROUP组） |
| 最内层 | Cluster (集群) | 物理/地域隔离 | 隔离不同机房（如北京、上海集群）以就近访问 （基本不设置，默认为空）                 |

## NacosConfig

读取 `APP_PATH/nacos.yaml`（`nacos` / `service` / `monitor` 等段），未配置项回退 `NACOS_*` 环境变量。

```php
$config = NacosConfig::load();
$client = $config->createClient($logger);
```

## ConfigFileWriter

将配置内容原子写入本地文件（如 `APP_PATH/.env`）。

```php
(new ConfigFileWriter($logger))->write('/path/to/.env', $content);
```

## ConfigFetcher

配置中心拉取与发布（默认使用 `nacos.data_id` / `group` / `tenant`）。

```php
$fetcher = new ConfigFetcher(NacosConfig::load(), $logger);
$fetcher->set($content);
$value = $fetcher->get();
```

## ServiceRegistrar

服务实例注册与列表查询（默认使用 `service` 段或 `NACOS_SERVICE_*`）。

```php
$registrar = new \Swoolefy\Support\Nacos\ServiceRegister(NacosConfig::load(), $logger);
$registrar->register('192.168.1.10', 8080, 'my-service');
// 临时实例注册成功后自动 goTick 每 10s 调用 instance/beat
$registrar->stopHeartbeat();
$list = $registrar->list('my-service');
```

## DiscoveryClient（服务发现 + 负载均衡）

| 配置值 / 类 | 策略 |
|---|---|
| `random` / `RandomLoadBalancer` | 随机 |
| `round_robin` / `RoundRobinLoadBalancer` | 轮询 |
| `weight` / `WeightLoadBalancer` | 权重 |

```php
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;

$client = DiscoveryClient::create('my-service', null, null, $logger);
$instance = $client->choose();
echo $instance->getUri(); // http://192.168.1.10:8080

// 或指定负载均衡
use Swoolefy\Support\Nacos\LoadBalancer\RoundRobinLoadBalancer;
$client->setLoadBalancer(new RoundRobinLoadBalancer($client));
```

`discovery` 段配置见 `Test/nacos.yaml`（`load_balancer`、`cache_ttl` 等）。

## Monitor

见 `Monitor/README.md`，`MonitorConfig` 组合 `NacosConfig` + `monitor` 段配置。
