# Nacos Monitor

配置变更后的标准流程：

1. 从 Nacos 拉取最新配置，写入 `APP_PATH/.env`
2. 后台执行 `php cli.php restart {APP_NAME} --force=1`（`RestartCmd`，应用名/PHP/入口脚本从运行时常量读取）
3. Nacos 连接参数由 `Swoolefy\Support\Nacos\NacosConfig` 读取 `APP_PATH/nacos.yaml`，缺失项再读环境变量

## 使用

在 `Test/Event.php` 注册自定义进程：

```php
ProcessManager::getInstance()->addProcess(
    'nacos-config-reload',
    \Test\Process\NacosProcess\NacosConfigReload::class,
    true, [], null, true,
);
```

或代码中：

```php
\Swoolefy\Support\Nacos\Monitor\NacosMonitor::run();
```

## 配置文件

复制 `Test/nacos.yaml` 到各应用 `APP_PATH/nacos.yaml` 并按环境修改。

## 日志

使用 `LogManager::getInstance()->getLogger('nacos_log')`，默认写入 `LOG_PATH/nacos/nacos.log`（见应用 `Config/component/log.php`）。
