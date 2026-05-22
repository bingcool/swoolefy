# Nacos Support

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
$registrar = new ServiceRegistrar(NacosConfig::load(), $logger);
$registrar->register('192.168.1.10', 8080, 'my-service');
// 临时实例注册成功后自动 goTick 每 10s 调用 instance/beat
$registrar->stopHeartbeat();
$list = $registrar->list('my-service');
```

## Monitor

见 `Monitor/README.md`，`MonitorConfig` 组合 `NacosConfig` + `monitor` 段配置。
