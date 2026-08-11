# Runtime Observability

`Swoolefy\Core\Runtime` 提供 **Worker 进程本地** 的运行时可观测能力：固定名称的指标、只读诊断快照，以及容量有界的内存趋势采样。它不提供跨 Worker 聚合、时序库导出器或持久化；每个 Worker 都有独立的 PHP 静态状态，Worker 重启后该 Worker 的计数与历史会重新开始。

> **响应迁移（当前生效）**：`GET /api/runtime` 的 `data` 现在严格只包含 `global` 和 `worker` 两个键。下面的“已废弃响应字段说明”仅供旧客户端迁移比对，不再描述当前响应；以本节和 [`docs/RuntimeResponseSchema.md`](../../../docs/RuntimeResponseSchema.md) 为准。

## 当前响应结构（权威）

```json
{
  "data": {
    "global": {
      "system": {
        "php_version": "8.4.0",
        "swoole_version": "6.1.0",
        "swoolefy_version": "6.2.0",
        "server_protocol": "http",
        "configured_worker_num": 8
      },
      "process": {
        "master_pid": 12340
      },
      "server": {
        "request_count": 1200,
        "connection_num": 12
      }
    },
    "worker": {
      "identity": {
        "worker_id": 0,
        "start_time": 1786258800,
        "uptime_seconds": 3600
      },
      "process": {
        "pid": 12345,
        "parent_pid": 12341
      },
      "coroutine": {
        "coroutine_num": 3,
        "coroutine_peak_num": 18,
        "coroutine_last_cid": 240
      },
      "memory": {
        "php_real_usage": 20971520,
        "state": "observing"
      },
      "metrics": {
        "request": {
          "counter": {"swoolefy_http_requests_total": 150},
          "gauge": {"swoolefy_http_requests_active": 1},
          "histogram": {}
        },
        "memory": {"gauge": {}},
        "pool": {
          "counter": {"swoolefy_pool_fetch_total": 40}
        }
      },
      "pool": {
        "aliases": {
          "redis": {
            "fetch_total": 40,
            "release_total": 39,
            "fetch_error_total": 1,
            "balance": 1
          }
        }
      }
    }
  }
}
```

分类规则：

- `global.system` 是静态 PHP/Swoole/Swoolefy 版本及安全的服务协议、Worker 配置元数据，绝不回传完整配置。
- `global.process.master_pid` 由服务 PID 文件读取；`global.server` 唯一来自 `Swoole\Server::stats()`，不以 Worker 本地计数器补全。
- `worker.process`、`worker.coroutine`、`worker.memory` 与 `worker.metrics` 都只表示**承载本次请求的一个 Worker**。`parent_pid` 仅是该 Worker 的 POSIX 父 PID，不能假定为 Swoole manager。
- 组件池别名只位于 `worker.pool.aliases`，没有顶层 `pool` 镜像。未知别名只增加 `worker.metrics.pool.counter.swoolefy_pool_unattributed_total`。
- 采集失败仍以对应分区的 `{"error":"collector_failed"}` 表示；关闭的 Worker 指标或内存分别为 `{"enabled":false}`。`server` 和协程字段集合随 Swoole 版本与服务类型变化。

## 启用、路由与访问控制

在 HTTP 服务配置的 `runtime_observability` 节点启用所需能力。默认模板位于 [`src/Http/conf.stub.php`](../../Http/conf.stub.php)，测试服务的等价配置位于 [`Test/Protocol/conf.php`](../../../Test/Protocol/conf.php)：

```php
'runtime_observability' => [
    'metrics' => ['enable' => true],
    'diagnostics' => ['enable' => true],
    'memory' => [
        'enable' => true,
        'sample_interval' => 10000, // ms，最小 1000
        'history_size' => 180,      // 最小 10
        'warmup_samples' => 6,
        'warning_growth' => 64 * 1024 * 1024,
        'critical_memory' => 0,     // 0 表示不按该阈值判定
        'critical_rss' => 0,        // 0 表示不按该阈值判定
        'positive_growth_ratio' => 0.7,
        'min_samples' => 12,
    ],
],
```

框架在 WorkerStart 时初始化，在 WorkerStop/WorkerExit 时停止采样定时器并清理本地状态。`metrics.enable`、`diagnostics.enable`、`memory.enable` 彼此独立：

- 诊断接口必须启用 `diagnostics.enable`；否则测试控制器会抛出 `Runtime diagnostics are not enabled`。
- 关闭 `metrics.enable` 时，诊断中的 `metrics` 固定为 `{"enabled": false}`；内存采样仍可独立启用，但其 `request_count` 无法从请求计数器取得，会为 `0`。
- 关闭 `memory.enable` 时，诊断中的 `memory` 固定为 `{"enabled": false}`。

当前仓库实际注册的是测试路由 **`GET /api/runtime`**（见 [`Test/Router/Common/Runtime.php`](../../../Test/Router/Common/Runtime.php)），控制器为 [`Test/Controller/RuntimeController.php`](../../../Test/Controller/RuntimeController.php)。可选查询参数 `memory_history=1` 才会返回有界的 `memory.samples`；未提供时不会输出该字段。

```bash
curl --fail --silent 'http://127.0.0.1:9501/api/runtime?memory_history=1' \
  -H 'Accept: application/json'
```

当前没有已注册的生产 Runtime Diagnostics HTTP 端点。`/process-runtime` **不是当前 `CtlApi` 的端点**；后者仅处理 `/process-list`、`/process-start`、`/process-stop`、`/process-status` 和 `/master-server-restart`。

设计中将 `GET /process-runtime?process_name=xxx` 定义为一个有前提的后续 control-plane 形态：它必须返回所选 Worker 的真实快照，并复用已认证、已授权且可审计的 control plane。当前 `RuntimeRegistry` 仅保存承载请求的 Worker 本地状态，`CtlApi` 也没有按 `process_name` 获取完整 Runtime Snapshot 的跨进程通道；此外，Cron/Daemon + HTTP 的入口判定只是路由范围限制，并非认证。因此，不应注册一个会返回错误 Worker 数据、或被误认为受认证保护的 `/process-runtime`。

`/api/runtime` 位于 `GroupTestMiddleware` 路由组中，该中间件不实施认证。它只用于测试，绝不能作为生产诊断入口。生产适配必须至少使用内网监听、网关/反向代理访问控制与强认证授权（建议仅运维角色可读），并且不能依赖“返回内容没有凭据”作为安全边界。

## 已废弃响应字段说明（迁移参考）

控制器返回的是诊断 `data`。下列完整 JSON 展示常见的全局响应封套形态；`code`、`msg`、`trace_id` 并非 `RuntimeDiagnostics` 生成。默认 `ResponseFormatter` 会生成前两者和 `data`，并仅在 OpenTelemetry 上下文存在时填充 `trace_id`；自定义 `response_formatter` 可以改变封套。`server` 与 `coroutine` 直接透传 Swoole API，实际键集合取决于 Swoole 版本、服务类型和编译特性。

```json
{
  "code": 0,
  "msg": "",
  "trace_id": "7dd6d45a2e2048b6",
  "data": {
    "system": {
      "php_version": "8.4.0",
      "swoole_version": "6.0.0",
      "swoolefy_version": "6.2.0",
      "server_protocol": "http"
    },
    "process": {
      "worker_pid": 12345,
      "master_pid": 12340,
      "manager_pid": 12341
    },
    "worker": {
      "worker_id": 0,
      "worker_num": 8,
      "start_time": 1786258800,
      "uptime_seconds": 3600
    },
    "server": {
      "start_time": 1786258790,
      "connection_num": 12,
      "accept_count": 1000,
      "close_count": 988,
      "tasking_num": 0,
      "task_worker_num": 1,
      "request_count": 1200,
      "worker_request_count": 1200,
      "worker_dispatch_count": 1200,
      "workers": [{"worker_id": 0, "worker_pid": 12345}],
      "task_workers": [{"worker_id": 8, "worker_pid": 12350}],
      "idle_worker_num": 7,
      "idle_task_worker_num": 1
    },
    "coroutine": {
      "coroutine_num": 3,
      "coroutine_peak_num": 18,
      "coroutine_last_cid": 240
    },
    "memory": {
      "timestamp": 1786262400,
      "request_count": 1200,
      "php_usage": 16777216,
      "php_real_usage": 20971520,
      "php_peak_usage": 25165824,
      "rss": 41943040,
      "coroutine_num": 3,
      "baseline": 18874368,
      "current": 20971520,
      "peak": 25165824,
      "growth": 2097152,
      "growth_rate": 0.1111111111,
      "positive_growth_ratio": 0.8,
      "rss_growth": 1048576,
      "requests_delta": 1000,
      "memory_per_1k_requests": 2097152,
      "state": "observing"
    },
    "metrics": {
      "global": {
        "server": {
          "request_count": 1200,
          "connection_num": 12
        }
      },
      "worker": {
        "request": {
          "counter": {"swoolefy_http_requests_total": 150, "swoolefy_http_5xx_total": 2},
          "gauge": {"swoolefy_http_requests_active": 1},
          "histogram": {"swoolefy_http_request_duration_seconds": {"count": 150, "sum": 1.95, "min": 0.001, "max": 0.08, "avg": 0.013}}
        },
        "memory": {
          "gauge": {"swoolefy_worker_uptime_seconds": 3600, "swoolefy_worker_memory_bytes": 20971520}
        },
        "pool": {
          "counter": {"swoolefy_pool_fetch_total": 40, "swoolefy_pool_release_total": 39},
          "aliases": {"redis": {"fetch_total": 40, "release_total": 39, "fetch_error_total": 1, "balance": 1}}
        }
      }
    },
    "pool": {
      "redis": {
        "fetch_total": 40,
        "release_total": 39,
        "fetch_error_total": 1,
        "balance": 1
      }
    }
  }
}
```

示例中的数字只用于说明字段关系，不是固定值或阈值。时间戳是 Unix 时间戳（秒），内存字节数均为 bytes。

## 封套字段

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `code` | `int` | — | 全局响应格式化器的业务码；默认成功值来自 `ResponseCode::CodeOk`。 | 不属于运行时快照。以项目实际 `response_formatter` 与错误处理约定判断成功。 |
| `msg` | `string` | — | 全局响应格式化器的消息。 | 默认成功格式化调用可为空字符串；不要将它当作诊断状态。 |
| `trace_id` | `string` | — | 默认格式化器仅在 OpenTelemetry trace context 存在时写入。 | 示例值表示已关联链路；无追踪上下文时通常为空字符串、缺失，或由自定义格式化器另行定义。 |
| `data` | `object` | — | 控制器返回的 `RuntimeDiagnostics::snapshot()`。 | 顶层严格且仅有 `global`、`worker`；以下路径均从 `data` 开始。 |

## 系统、进程与 Worker 字段

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `global.system.php_version` | `string` | — | PHP 常量 `PHP_VERSION`。 | 用于确认服务运行时版本。 |
| `global.system.swoole_version` | `string\|null` | — | 存在 `swoole_version()` 时调用它。 | `null` 表示函数不可用；正常 Swoole 服务一般应有值。 |
| `global.system.swoolefy_version` | `string\|null` | — | 常量 `SWOOLEFY_VERSION`。 | 常量未定义时为 `null`，不是采集失败。 |
| `global.system.server_protocol` | `string` | — | `BaseServer::getServiceProtocol()`。 | 当前主服务识别出的协议，例如 `http`、`websocket`、`tcp`、`udp` 或 MQTT。 |
| `global.system.configured_worker_num` | `int\|null` | 个 | Swoole `setting['worker_num']`。 | 配置的普通 Worker 数，不是当前存活数。 |
| `global.process.master_pid` | `int\|null` | PID | 服务 `pid_file` 经 `PidFileManager::read()` 读取。 | PID 文件不可读、为空或失效时可为空；不应据此直接执行进程操作。 |
| `worker.process.pid` | `int\|null` | PID | `getmypid()`。 | 当前响应所在 Worker 的 PID，不代表所有 Worker。 |
| `worker.process.parent_pid` | `int\|null` | PID | `posix_getppid()`（可用时）。 | 当前 Worker 父 PID；部署模式不同，不可将其断言为 Swoole manager。 |
| `worker.identity.worker_id` | `int\|null` | — | Swoole Server 的 `worker_id`。 | 用于定位该 Worker。 |
| `worker.identity.start_time` | `int\|null` | Unix 秒 | RuntimeRegistry 在该 Worker 初始化时记录。 | Worker 重启后改变；不是 master/server 的启动时间。 |
| `worker.identity.uptime_seconds` | `int\|null` | 秒 | `time() - worker.identity.start_time`，最小为 `0`。 | `start_time` 未初始化时为 `null`。 |

若任一采集器抛出异常，对应对象会替换为 `{"error":"collector_failed"}`，而其他区块仍会返回。这是失败隔离快照，不代表整份诊断失败。

## `global.server`：Swoole 服务统计

`global.server` 是 `BaseServer::getServer()->stats()` 的原样结果，而非 Runtime 模块维护的计数器。下表解释示例中出现的常见键；Swoole 会随版本、HTTP/TCP/WebSocket 服务类型、Task Worker 配置和编译特性增加、删除或改变可用键，客户端必须容忍未知键和缺失键。

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `server.start_time` | `int` | Unix 秒 | Swoole `stats()`。 | 服务启动时间；与单个 `worker.start_time` 不同。 |
| `server.connection_num` | `int` | 条 | 当前连接数。 | TCP/WebSocket 等长连接服务更有参考价值；不要简单等同于 HTTP 请求并发。 |
| `server.accept_count` | `int` | 次 | 累计接受连接数。 | 自服务启动起累计，重启后归零。 |
| `server.close_count` | `int` | 次 | 累计关闭连接数。 | 与 `accept_count`、`connection_num` 联合观察，不应单独判故障。 |
| `server.tasking_num` | `int` | 个 | 当前正在处理或排队的 Task 数。 | 仅配置/使用 Task Worker 时有意义；持续增长意味着任务吞吐可能不足。 |
| `server.task_worker_num` | `int` | 个 | Task Worker 数。 | 未配置 Task Worker 时可为 `0`、缺失或版本相关。 |
| `server.request_count` | `int` | 次 | Swoole 累计请求数。 | Swoole 的计数口径；当前路径为 `global.server.request_count`。不要与框架的 `worker.metrics.request.counter` 强行视为完全相同。 |
| `server.worker_request_count` | `int` | 次 | Swoole Worker 已处理请求累计数。 | 具体统计范围由 Swoole 决定，版本间可能不同。 |
| `server.worker_dispatch_count` | `int` | 次 | Swoole 分派给 Worker 的累计次数。 | 可辅助观察分派量；不等于业务成功数。 |
| `server.workers` | `array<object>` | — | Swoole Worker 状态列表。 | 每个元素的字段由 Swoole 决定；可能很大，轮询时注意响应成本。 |
| `server.workers[].worker_id` | `int` | — | `workers` 元素中的 Worker 编号。 | 用于与 `worker.worker_id` 对照。 |
| `server.workers[].worker_pid` | `int` | PID | `workers` 元素中的进程 PID。 | Worker 重启会变化；不要缓存为永久身份。 |
| `server.task_workers` | `array<object>` | — | Swoole Task Worker 状态列表。 | Task Worker 不存在或该版本不提供时可缺失。 |
| `server.task_workers[].worker_id` | `int` | — | `task_workers` 元素中的编号。 | Swoole 分配的 Task Worker 标识。 |
| `server.task_workers[].worker_pid` | `int` | PID | `task_workers` 元素中的 PID。 | 仅用于观测定位。 |
| `server.idle_worker_num` | `int` | 个 | 空闲普通 Worker 数。 | 版本支持时返回；长期接近 `0` 只能说明繁忙信号，需结合延迟和 CPU 判断。 |
| `server.idle_task_worker_num` | `int` | 个 | 空闲 Task Worker 数。 | 版本支持时返回；Task Worker 未启用时可能为 `0` 或缺失。 |

## `worker.coroutine`：Swoole 协程统计

`worker.coroutine` 直接返回 `Swoole\Coroutine::stats()`；它是当前 Worker 的协程状态。该对象的键由当前 Swoole 版本决定。若 `stats()` 方法不可用，框架返回空对象 `{}`；采集异常则为 `{"error":"collector_failed"}`。

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `coroutine.coroutine_num` | `int` | 个 | 当前存活协程数量。 | 观察其是否在负载回落后仍持续上升；单次偏高不等于协程泄漏。 |
| `coroutine.coroutine_peak_num` | `int` | 个 | 服务生命周期内协程数量峰值。 | 用于容量规划和排查瞬时并发；重启后归零。 |
| `coroutine.coroutine_last_cid` | `int` | — | 最近分配的协程 ID。 | 是递增标识，不是当前活跃协程数，不能用它判断泄漏。 |

## `worker.memory`：最新采样与趋势

内存采样在当前 Worker 启动时立即执行，随后由 `Swoole\Timer::tick()` 按 `sample_interval` 在请求路径外执行；生成诊断快照不会额外读取 `/proc` 或强制采样。历史长度受 `history_size` 限制。`memory_history=1` 时额外出现 `worker.memory.samples`，每个元素只包含前七个“最新采样”字段。

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `memory.timestamp` | `int` | Unix 秒 | 最近一次采样的 `time()`。 | 是采样时刻，不是 HTTP 响应时刻。 |
| `memory.request_count` | `int` | 次 | 最近采样时的 `swoolefy_http_requests_total`。 | 指标关闭时为 `0`；用于将内存变化与请求量关联。 |
| `memory.php_usage` | `int` | bytes | `memory_get_usage(false)`。 | PHP 实际已用内存，不含 PHP 向系统申请但暂未使用的空间。 |
| `memory.php_real_usage` | `int` | bytes | `memory_get_usage(true)`。 | PHP 向系统申请的内存；趋势判定的主要信号。 |
| `memory.php_peak_usage` | `int` | bytes | `memory_get_peak_usage(true)`。 | 当前 Worker 生命周期内 PHP 申请内存峰值，通常不会回落。 |
| `memory.rss` | `int\|null` | bytes | Linux `/proc/<pid>/status` 的 `VmRSS`（kB 转 bytes）。 | 非 Linux、`/proc` 不可读或格式不匹配时为 `null`；不是 PHP 堆的同义词。 |
| `memory.coroutine_num` | `int` | 个 | 采样时 `Swoole\Coroutine::stats()['coroutine_num']`。 | 用于与内存走势关联，当前实现不据此自动判定协程泄漏。 |
| `memory.baseline` | `int` | bytes | 预热样本中 `php_real_usage` 的下中位数。 | 预热阶段避免把加载/初始化分配误判为增长；样本不足 `min_samples` 时为 `0`。 |
| `memory.current` | `int` | bytes | 最近样本的 `php_real_usage`。 | 与 `baseline` 比较，而不是与 `php_usage` 比较。 |
| `memory.peak` | `int` | bytes | 最近样本的 `php_peak_usage`。 | 是 PHP 峰值，不是 RSS 峰值。 |
| `memory.growth` | `int` | bytes | `current - baseline`。 | 正值表示相对基线增长；负值并不异常。样本不足时为 `0`。 |
| `memory.growth_rate` | `float` | 比率 | `growth / baseline`，基线为 `0` 时为 `0.0`。 | 例如 `0.1` 即相对基线约增长 10%；不是百分数字符串。 |
| `memory.positive_growth_ratio` | `float` | 0–1 | 相邻样本中 `php_real_usage` 上升次数占比。 | 达到配置阈值才是 `suspected` 的条件之一；不足样本时为 `0.0`。 |
| `memory.rss_growth` | `int\|null` | bytes | 最新与首个历史样本的 RSS 差。 | 任一 RSS 不可用则为 `null`；可用时，正增长才会为 PHP 增长提供 RSS 佐证。 |
| `memory.requests_delta` | `int` | 次 | 最新与首个历史样本的请求数差，最小为 `0`。 | 当指标关闭或该时段无请求时为 `0`。 |
| `memory.memory_per_1k_requests` | `float\|null` | bytes/1,000 请求 | `growth / requests_delta * 1000`。 | `requests_delta = 0` 时为 `null`，不能除零；用于比较不同流量区间，不能单独证明泄漏。 |
| `memory.state` | `string` | — | 内存趋势状态机输出。 | 见下一节；状态是诊断提示，不会自动重启 Worker 或回收内存。 |
| `memory.samples` | `array<object>` | — | 仅 `memory_history=1` 时输出的有界历史。 | 最多为 `history_size`（且不低于 10）条；频繁请求该参数会增加响应体和敏感运行态暴露面。 |

状态含义：

- `normal`：没有样本、样本数少于 `min_samples`，或不存在正向 `php_real_usage` 增长。
- `observing`：存在正向增长，但尚未同时满足“增长达到 `warning_growth`、上升比例达到 `positive_growth_ratio`、RSS 允许/增长”的怀疑条件。它也可能表示 PHP 增长而 RSS 没有佐证。
- `suspected`：`growth >= warning_growth`，正向增长比例达标，并且 RSS 不可用或 `rss_growth > 0`。应结合请求量、协程数、业务缓存与多次时间窗复核。
- `critical`：启用了且命中 `critical_memory`（`php_real_usage`）或 `critical_rss`（RSS）阈值。两个阈值为 `0` 时各自不参与判定。

## `worker.metrics`：当前 Worker 框架指标

`worker.metrics` 是当前 Worker 的 `RuntimeRegistry` PHP 静态状态。服务级 Swoole 指标仅位于 `global.server`；框架绝不把 PHP 静态注册表中的数据声称为全服务总数。

| 字段 | 类型 | 范围、来源与中文含义 |
| --- | --- | --- |
| `worker.metrics` | `object` 或 `{"enabled":false}` | 当前响应实际落到的**单个 Worker** 的 `RuntimeRegistry` 快照。Worker 重启清零；负载均衡下不同请求可能命中不同 Worker。 |
| `worker.metrics.request.counter` | `object<string,int>` | 当前 Worker 的请求与错误累计计数器。 |
| `worker.metrics.request.gauge.swoolefy_http_requests_active` | `int` | 当前 Worker 请求路径内的活跃请求数。 |
| `worker.metrics.request.histogram.swoolefy_http_request_duration_seconds` | `object` | 当前 Worker 已完成请求的秒级摘要；含 `count`、`sum`、`min`、`max`、`avg`。 |
| `worker.metrics.memory.gauge` | `object<string,int\|float>` | 当前 Worker 的内存采样 Gauge；未采样的键会缺失。 |
| `worker.metrics.pool.counter` | `object<string,int>` | 当前 Worker 所有受跟踪池的固定汇总计数器，以及未知别名的 `swoolefy_pool_unattributed_total`。 |

所有 Worker 指标只会在相应行为发生后创建，**缺失不等于零**。`global.server` 只是 Swoole 当次 `stats()` 快照，不与 `worker.metrics` 做数学求和或一致性校验。若需要框架计数器的跨 Worker 全局值，必须由外部采集器汇总。

## `worker.pool`：按组件别名归因

| 字段 | 类型 | 单位 | 来源/含义 | 解读与运维说明 |
| --- | --- | --- | --- | --- |
| `worker.pool.aliases` | `object<string,object>` | — | 以 `component_pools` 配置的组件别名为键，例如 `worker.pool.aliases.redis`。 | 别名白名单仅在 Worker 启动时建立；没有配置组件池时为 `{}`。 |
| `worker.pool.aliases.<alias>.fetch_total` | `int` | 次 | 该别名成功从组件池取得对象的累计数。 | 对应全部池汇总计数器的一个归因分量。 |
| `worker.pool.aliases.<alias>.release_total` | `int` | 次 | 该别名受跟踪对象成功归还组件池的累计数。 | 只覆盖 `ComponentTrait` 当前的池化对象生命周期。 |
| `worker.pool.aliases.<alias>.fetch_error_total` | `int` | 次 | 该别名获取时抛异常或返回非对象的累计数。 | 不含错误原因。 |
| `worker.pool.aliases.<alias>.balance` | `int` | 次 | 该别名的 `fetch_total - release_total`。 | 正值仅是诊断信号，不能据此断言连接泄漏。 |

`worker.pool.aliases` 只暴露已配置的组件别名，不暴露连接对象、DSN 或容量；别名不来自请求输入，因此不会产生动态高基数。未知、空白或缺失别名的事件不会插入此映射，也不会静默归属到其他池，而会累计到固定的 `worker.metrics.pool.counter.swoolefy_pool_unattributed_total`。它只覆盖 `ComponentTrait` 中实际池化组件的成功 fetch/release 及 fetch 失败路径，不能代表应用自行管理的任意连接池。

## 生产使用建议

1. **保护入口**：仅通过管理网、VPN 或 mTLS/网关认证访问；限制运维角色，并记录审计日志。不要公开、不要把原始响应转发给浏览器或第三方。
2. **控制轮询**：该快照是单 Worker、瞬时且非原子聚合。使用低频轮询（例如数十秒级）并在监控系统端聚合；不要让每个用户请求再请求一次诊断接口。
3. **避免高基数**：仅使用已有固定指标名。不要把 URL、用户 ID、异常消息、连接池名称或请求参数拼进指标键。
4. **正确处理不可用值**：`null` 是“当前平台/API 无法提供”，缺失通常是“该指标尚未创建或该 Swoole 版本未输出”，`{"error":"collector_failed"}` 是单个采集器失败。三者都不能直接按 `0` 处理。
5. **理解失败隔离**：每个诊断分区独立捕获异常，确保一个 Swoole 状态读取失败不会破坏其余响应；因此同一份快照中的字段并非严格同一时刻采集，也不应作为自动化杀进程/扩缩容的唯一依据。
6. **谨慎开启历史**：`memory_history=1` 只返回有界标量样本，但仍会增大响应和运行态暴露面。默认不带历史；只在受保护的排障会话中按需使用。
