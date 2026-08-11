# `/api/runtime` 响应结构

## 严格外层结构

`GET /api/runtime` 由全局响应格式化器包裹。`code`、`msg`、可选的 `trace_id` 不是 Runtime 快照字段；本接口返回的 `data` **必须且只能**有以下两个键：

```json
{
  "data": {
    "global": {},
    "worker": {}
  }
}
```

客户端不得依赖 `data` 下任何其他顶层键。

## 完整中文 Schema

```json
{
  "data": {
    "global": {
      "system": {
        "php_version": "string：PHP 版本",
        "swoole_version": "string|null：Swoole 版本；函数不可用时为 null",
        "swoolefy_version": "string|null：Swoolefy 版本；常量未定义时为 null",
        "server_protocol": "string：当前服务协议，例如 http",
        "configured_worker_num": "int|null：配置的普通 Worker 数量"
      },
      "process": {
        "master_pid": "int|null：从服务 pid_file 读取的 Master PID"
      },
      "server": {
        "…": "Swoole Server::stats() 原样快照；键由 Swoole 版本、协议与编译特性决定"
      }
    },
    "worker": {
      "identity": {
        "worker_id": "int|null：当前 Swoole Worker ID",
        "start_time": "int|null：该 Worker RuntimeRegistry 初始化的 Unix 秒",
        "uptime_seconds": "int|null：当前 Unix 秒减去该 Worker start_time"
      },
      "process": {
        "pid": "int|null：处理本次请求的 Worker PID",
        "parent_pid": "int|null：当前 Worker 的 POSIX 父进程 PID；扩展不可用时为 null"
      },
      "coroutine": {
        "…": "CoroutineManager::getCoroutineStatus() 原样快照，例如 coroutine_num"
      },
      "memory": {
        "enabled": "false：memory.enable 关闭时存在",
        "…": "启用后为当前 Worker 的最新内存采样与趋势；memory_history=1 时包含有界 samples"
      },
      "metrics": {
        "enabled": "false：metrics.enable 关闭时存在",
        "request": {
          "counter": "当前 Worker 请求与错误累计计数器",
          "gauge": "当前 Worker 活跃请求 Gauge",
          "histogram": "当前 Worker 已完成请求耗时摘要"
        },
        "memory": {
          "gauge": "当前 Worker 内存采样同步的 Gauge"
        },
        "pool": {
          "counter": "当前 Worker 全部受跟踪池的固定汇总计数器"
        }
      },
      "pool": {
        "aliases": {
          "<启动期 component_pools 别名>": {
            "fetch_total": "int",
            "release_total": "int",
            "fetch_error_total": "int",
            "balance": "fetch_total - release_total"
          }
        }
      }
    }
  }
}
```

## 来源与分类规则

| 分区 | 数据来源 | 语义边界 |
| --- | --- | --- |
| `global.system` | PHP 常量、Swoole 版本函数、Swoolefy 常量、公开服务元数据 | 静态平台与安全服务配置，不含敏感完整配置 |
| `global.process` | pid 文件 | Master PID 定位信息 |
| `global.server` | `Swoole\Server::stats()` | 唯一服务级 Swoole stats 来源 |
| `worker.identity` | 当前 Swoole Worker、`RuntimeRegistry` | 当前 Worker ID 与生命周期 |
| `worker.process` | `getmypid()`、`posix_getppid()` | 本次请求所在 Worker 的进程信息；父 PID 不能假定为 Manager |
| `worker.coroutine` | `CoroutineManager::getCoroutineStatus()` | 当前 Worker 的协程状态 |
| `worker.memory` | 当前 Worker 的 `MemoryMonitor` | 当前 Worker 的内存采样及趋势 |
| `worker.metrics` | 当前 Worker 的 `RuntimeRegistry` PHP 静态指标 | 不跨 Worker 聚合 |
| `worker.pool.aliases` | 当前 Worker 启动期 `component_pools` 白名单 | 唯一池别名位置 |

禁止将单个 Worker 的 PID、协程数、内存、框架请求/错误计数或池计数放入 `global`。接口不会计算或伪造跨 Worker 的框架指标总和；需要汇总时由外部采集器按 Worker 采集。

## 迁移说明

旧路径全部已移除：

| 旧路径 | 新路径 |
| --- | --- |
| `data.runtime` / `data.system` | `data.global.system` |
| `data.process.worker_pid` / `manager_pid` | `data.worker.process.pid` / `parent_pid` |
| `data.process.master_pid` | `data.global.process.master_pid` |
| `data.worker.worker_id` / 生命周期 | `data.worker.identity.*`；配置 Worker 数为 `data.global.system.configured_worker_num` |
| `data.server` | `data.global.server` |
| `data.coroutine` | `data.worker.coroutine` |
| `data.memory` | `data.worker.memory` |
| `data.metrics.global.server` | `data.global.server`（不再重复） |
| `data.metrics.worker.*` | `data.worker.metrics.*` |
| `data.pool` | `data.worker.pool.aliases`（不再镜像） |

旧客户端应一次性切换路径。不得同时读取旧、新路径，也不得把 `worker` 数据当作全服务合计。
