# Nacos Monitor

配置变更后的标准流程：

1. 从 Nacos 拉取最新配置，写入 `APP_PATH/.env`
2. 后台执行 `php cli.php restart {APP_NAME} --force=1`（`RestartCmd`，应用名/PHP/入口脚本从运行时常量读取）
3. Nacos 服务器连接读 `APP_PATH/nacos.yaml`；配置中心 dataId 读 `application.yaml` → `nacos.service_config`；监听项读 `nacos.monitor_config_change`

## k8s 部署

`MonitorConfig::k8sMode` 为真时（显式 `--fromk8s=1`，或 Pod 内存在 `KUBERNETES_SERVICE_HOST`），流程改为：

1. 不拉取、不写 `.env`，也不做随机延迟——写进容器可写层的内容会随容器销毁
2. 向 PID 文件记录的服务主进程发 SIGTERM（`ServiceRestarter::terminateMasterForKubeletRestart()`）
3. 服务退出 → 容器终止 → kubelet 按 `restartPolicy` 原地重建容器
4. 新容器执行 `cli.php start`，入口脚本的 `$beforeFunc` 调 `NacosFactory::fetchConfigToEnv()` 拉取最新配置写入 `.env` 后再启动

因此在 k8s 下配置来源是"Nacos + 启动时拉取"，不依赖 `.env` 本地残留；前提是入口脚本保留 `$beforeFunc`。注意 `StartCmd` 对该回调没有异常兜底，Nacos 不可达或配置非法时容器启动失败，表现为 CrashLoopBackOff。

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

复制 `Test/nacos.yaml` 与 `Test/application.yaml` 到各应用 `APP_PATH` 并按环境修改。

环境变量说明见 [../README.md#env-vars](../README.md#env-vars)。

## 日志

使用 `LogManager::getInstance()->getLogger('nacos_log')`，默认写入 `LOG_PATH/nacos/nacos.log`（见应用 `Config/component/log.php`）。
