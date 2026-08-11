# Swoolefy 6.2-x Runtime Observability 生产级代码设计方案

> 目标分支：`swoolefy-6.2-x`  
> 设计范围：**Runtime Metrics + Worker Runtime Diagnostics + Worker Memory Leak Detection**  
> 原则：**不重复实现已有能力、不改变现有 App/Component/ZFactory/Pool/Context/Worker 生命周期、不新增 APM 平台、不自动重启 Worker。**   
> 结果：**本方案已实现**  
---

## 0.1 `/api/runtime` 严格作用域响应（现行）

`RuntimeRegistry` 是 Worker 进程内的 PHP 静态状态，不能跨 Worker 聚合。因此 `/api/runtime` 的 `data` 严格只有 `global` 与 `worker`：

```json
{
  "data": {
    "global": {
      "system": {},
      "process": {},
      "server": {}
    },
    "worker": {
      "identity": {},
      "process": {},
      "coroutine": {},
      "memory": {},
      "metrics": {
        "request": {},
        "memory": {},
        "pool": {"counter": {}}
      },
      "pool": {"aliases": {"redis": {}}}
    }
  }
}
```

- `global.server` 是唯一的 `Swoole\Server::stats()` 服务级统计来源；不再存在重复的 `metrics.global.server`。
- `worker.identity`、`worker.coroutine`、`worker.memory`、`worker.metrics` 只描述承载本次 `/api/runtime` 请求的一个 Worker。请求切换 Worker、Worker 重启和多进程部署都会使数值不可直接当作服务合计。
- `worker.process.pid`、`parent_pid` 也是当前 Worker 数据；后者只是 POSIX 父 PID，不能假定为 Swoole manager。池别名只保留于 `worker.pool.aliases`，不再有 `data.pool` 兼容镜像。未知别名仍只计入固定的 `swoolefy_pool_unattributed_total`，不会扩展标签集合。
- 完整中文 Schema、字段来源和旧键迁移表见 [`docs/RuntimeResponseSchema.md`](RuntimeResponseSchema.md)；运行时操作说明见 [`src/Core/Runtime/README.md`](../src/Core/Runtime/README.md)。

## 0. 本方案不是通用 Metrics 方案，而是针对 Swoolefy 6.2-x 的设计

本方案基于当前 `swoolefy-6.2-x` 源码重新梳理后的实际运行链路设计。

当前框架已经具备很多基础能力：

- `App::run()` / `App::end()` 请求生命周期
- `Application` 按 Coroutine ID 保存 App
- `ZFactory` 按 Coroutine ID 保存协程单例
- `ComponentTrait` 管理组件、组件池对象以及归还
- `Context` 管理协程上下文
- `CoroutineManager` 已提供 Coroutine stats/list/backtrace
- `BaseServer::getStats()` 已直接暴露 Swoole Server stats
- `BaseServer::workerStartInit()` / WorkerStart / WorkerStop / WorkerExit 生命周期
- `AbstractBaseWorker` 已经有 Worker PID、Worker ID、Master PID、Coroutine 数量、Memory 状态、进程重启和退出机制
- `SysProcess` 已经可以周期采集系统信息和请求 PV，并通过 UDP / Redis / File 等方式发送
- `CtlApi` 已有 `/process-list`、`/process-status` 等进程控制面
- HTTP 已经集成 OpenTelemetry
- `HttpServer` 已有 request / WorkerStart / WorkerStop / WorkerExit 等明确入口

因此，**不能再设计一个脱离现有架构的 Metrics/Diagnostics 系统。**

正确方案应该是：

```text
                         Swoolefy Runtime
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
          ▼                   ▼                   ▼
         App              Component             Worker
          │                   │                   │
          ▼                   ▼                   ▼
      Request             Pool/Context       Process Runtime
          │                   │                   │
          └───────────────────┼───────────────────┘
                              ▼
                    Runtime Observability
                              │
              ┌───────────────┼───────────────┐
              ▼               ▼               ▼
           Metrics       Diagnostics       Memory
```

参考源码：

- `App::run()` / `App::end()`：`src/Core/App.php`
- `Application`：`src/Core/Application.php`
- `ZFactory`：`src/Core/ZFactory.php`
- `ComponentTrait`：`src/Core/ComponentTrait.php`
- `Context`：`src/Core/Coroutine/Context.php`
- `CoroutineManager`：`src/Core/Coroutine/CoroutineManager.php`
- `BaseServer`：`src/Core/BaseServer.php`
- `HttpServer`：`src/Http/HttpServer.php`
- `AbstractBaseWorker`：`src/Worker/AbstractBaseWorker.php`
- `SysProcess`：`src/Core/SysCollector/SysProcess.php`
- `CtlApi`：`src/Worker/CtlApi.php`

---

# 1. 当前 Swoolefy 运行模型

## 1.1 HTTP 请求生命周期

当前 HTTP 主链路实际是：

```text
Swoole HttpServer
      │
      ▼
HttpServer::request
      │
      ├── OpenTelemetry
      ├── HeaderContext
      ├── FrameworkContext
      │
      ▼
static::onRequest()
      │
      ▼
App::run()
      │
      ├── parseHeaders()
      ├── initCoreComponent()
      ├── create RequestInput / ResponseOutput
      ├── Application::setApp()
      ├── defer()
      ├── _init()
      ├── _bootstrap()
      └── HttpRoute::dispatch()
              │
              ▼
         Middleware
              │
              ▼
          Controller
              │
              ▼
          Business
              │
              ▼
       App::end()/defer
              │
              ├── handleLog()
              ├── ZFactory::removeInstance()
              ├── pushComponentPools()
              ├── clearComponent()
              ├── Application::removeApp()
              ├── removeControllerInstance()
              └── response->end()
```

`App::run()` 已经使用 `try/catch/finally`，异常后仍会执行 `onAfterRequest()`，并在没有 defer 时执行 `end()`；Coroutine 环境下则通过 `Coroutine::defer()` 最终执行 `end()`。因此 Metrics 不应该重新建立一套请求生命周期。citeturn7view0

## 1.2 App / Coroutine Context

`Application::$apps` 使用 Coroutine ID 作为 key；同一 CID 不允许覆盖不同 App。`Context` 优先使用 Swoole Coroutine Context，没有 Coroutine 且存在 Application 时才使用 App 自身 Context，而且传播快照只允许 scalar/array/null。citeturn7view3turn7view4

因此：

> **Runtime Metrics 的 Worker 状态不应该写进 `Context`。**

Context 是请求/协程业务上下文，不是 Worker Runtime Registry。

## 1.3 ZFactory

`ZFactory::$_instances[$cid][$class]` 是协程级单例，`removeInstance()` 在 `App::end()` 中调用。citeturn7view1turn7view0

因此：

```text
RuntimeMetrics
RuntimeDiagnostics
MemoryMonitor
MemoryHistory
```

都不应该成为普通业务组件后由 `ZFactory` 按请求创建，否则会把 Worker 级 Runtime 状态错误地变成 Coroutine 级状态。

## 1.4 Component / Pool

`ComponentTrait` 中已经存在：

```text
containers
componentPools
componentPoolsObjIds
```

`get()` 在配置启用组件池时通过 `CoroutinePools::getInstance()->getPool($name)->fetchObj()` 获取对象，并用 `spl_object_id()` 标识来自池的对象；`pushComponentPools()` 在请求结束时归还对象。citeturn8view0

因此 Pool Metrics 必须围绕现有：

```text
fetchObj()
↓
componentPoolsObjIds
↓
pushObj()
```

进行，而不能另外创建一个 Pool Registry 去猜测池状态。

---

# 2. 先明确：哪些能力已经存在，不能重复开发

| 已有能力 | 当前实现 | 本方案处理方式 |
|---|---|---|
| Server stats | `BaseServer::getStats()` | 直接复用 |
| Coroutine stats | `CoroutineManager::getCoroutineStatus()` | 直接复用 |
| Coroutine list | `CoroutineManager::listCoroutines()` | Diagnostics 按需读取 |
| Coroutine backtrace | `getBackTrace()` | Diagnostics 可选使用 |
| Worker PID/ID | `AbstractBaseWorker` | 直接复用 |
| Worker Memory/Coroutine | `getProcessSystemStatus()` | 扩展/统一输出，不重复采集 |
| Worker 生命周期 | `BaseServer` / `AbstractBaseWorker` | 接入现有 hook |
| PV 采集 | `SysProcess` + Atomic | 不重复实现 PV exporter |
| OpenTelemetry | `HttpServer` | Metrics 不替代 Trace |
| Process Status | `CtlApi` | Diagnostics 与其复用数据模型 |
| Pool 生命周期 | `ComponentTrait` / `CoroutinePools` | 在现有 fetch/push 点埋点 |
| Request 生命周期 | `App::run()` | 在现有入口埋点 |

这点非常重要。

**Runtime Observability 的核心不是增加大量代码，而是把已有 Runtime 状态统一组织起来。**

---

# 3. 最终模块结构

建议新增：

```text
src/
└── Core/
    └── Runtime/
        ├── Metrics/
        │   ├── Counter.php
        │   ├── Gauge.php
        │   ├── Histogram.php
        │   ├── MetricsRegistry.php
        │   ├── RuntimeMetrics.php
        │   └── MetricSnapshot.php
        │
        ├── Diagnostics/
        │   ├── RuntimeDiagnostics.php
        │   ├── DiagnosticSnapshot.php
        │   └── DiagnosticCollectorInterface.php
        │
        └── Memory/
            ├── MemoryMonitor.php
            ├── MemorySnapshot.php
            ├── MemoryHistory.php
            ├── MemoryTrend.php
            └── MemoryLeakDetector.php
```

**不要新增一个巨大的 `RuntimeManager.php`。**

职责必须清楚：

```text
MetricsRegistry
    管理指标

RuntimeMetrics
    提供框架埋点 API

RuntimeDiagnostics
    读取现有 Runtime 状态并生成 Snapshot

MemoryMonitor
    采集内存

MemoryHistory
    保存有限历史

MemoryLeakDetector
    分析趋势
```

---

# 4. Runtime Metrics 的正确定位

## 4.1 Metrics 是 Worker-local

Swoolefy 是多进程模型：

```text
Master
Manager
Worker 0
Worker 1
Worker 2
...
```

Worker 内存并不共享。

所以：

```php
MetricsRegistry
```

必须是：

```text
Worker Process Local
```

而不是：

```text
static 全局跨 Worker
Redis
Table
```

第一版不做跨 Worker 聚合。

如果需要外部聚合，由现有 `SysProcess` / UDP / Redis / OpenTelemetry / 外部 Prometheus exporter 负责。

---

# 5. Counter

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

final class Counter
{
    private int $value = 0;

    public function increment(int $value = 1): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Counter increment must be >= 0.');
        }

        $this->value += $value;
    }

    public function value(): int
    {
        return $this->value;
    }
}
```

Counter 不提供 decrement。

---

# 6. Gauge

```php
<?php

declare(strict_types=1);

namespace Swoolefy\Core\Runtime\Metrics;

final class Gauge
{
    private int|float $value = 0;

    public function set(int|float $value): void
    {
        $this->value = $value;
    }

    public function increment(int|float $value = 1): void
    {
        $this->value += $value;
    }

    public function decrement(int|float $value = 1): void
    {
        $this->value -= $value;
    }

    public function value(): int|float
    {
        return $this->value;
    }
}
```

---

# 7. Histogram

第一版不做复杂 percentile reservoir。

原因：

- 每个 Worker 独立
- HTTP 高频路径
- percentile 如果直接保存所有 duration 会造成内存增长
- Swoolefy 已有 OpenTelemetry，复杂 Trace/Histogram 可以交给 OTEL

建议第一版只保留：

```text
count
sum
min
max
```

代码：

```php
final class Histogram
{
    private int $count = 0;
    private float $sum = 0.0;
    private float $min = 0.0;
    private float $max = 0.0;

    public function observe(float $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('Histogram value must be >= 0.');
        }

        if ($this->count === 0) {
            $this->min = $value;
            $this->max = $value;
        } else {
            $this->min = min($this->min, $value);
            $this->max = max($this->max, $value);
        }

        ++$this->count;
        $this->sum += $value;
    }

    public function snapshot(): array
    {
        return [
            'count' => $this->count,
            'sum' => $this->sum,
            'min' => $this->min,
            'max' => $this->max,
            'avg' => $this->count > 0 ? $this->sum / $this->count : 0.0,
        ];
    }
}
```

---

# 8. MetricsRegistry

```php
final class MetricsRegistry
{
    /** @var array<string, Counter> */
    private array $counters = [];

    /** @var array<string, Gauge> */
    private array $gauges = [];

    /** @var array<string, Histogram> */
    private array $histograms = [];

    public function counter(string $name): Counter
    {
        return $this->counters[$name] ??= new Counter();
    }

    public function gauge(string $name): Gauge
    {
        return $this->gauges[$name] ??= new Gauge();
    }

    public function histogram(string $name): Histogram
    {
        return $this->histograms[$name] ??= new Histogram();
    }

    public function snapshot(): array
    {
        $result = [
            'counter' => [],
            'gauge' => [],
            'histogram' => [],
        ];

        foreach ($this->counters as $name => $metric) {
            $result['counter'][$name] = $metric->value();
        }

        foreach ($this->gauges as $name => $metric) {
            $result['gauge'][$name] = $metric->value();
        }

        foreach ($this->histograms as $name => $metric) {
            $result['histogram'][$name] = $metric->snapshot();
        }

        return $result;
    }
}
```

---

# 9. RuntimeMetrics 不做通用 Label 系统

第一版不要设计：

```php
$metrics->counter('request', [
    'route' => $route,
    'user_id' => $userId,
]);
```

因为在常驻 Worker 中，这很容易产生高 Cardinality：

```text
user_id
request_id
trace_id
URI
exception
```

最终变成：

```text
Metrics Registry 无限增长
```

Swoolefy 第一版应该使用固定指标名。

---

# 10. 推荐指标集合

## Worker

```text
swoolefy_worker_requests_total
swoolefy_worker_errors_total
swoolefy_worker_uptime_seconds
swoolefy_worker_memory_bytes
swoolefy_worker_peak_memory_bytes
swoolefy_worker_rss_bytes
```

## HTTP

```text
swoolefy_http_requests_total
swoolefy_http_requests_active
swoolefy_http_errors_total
swoolefy_http_request_duration_seconds
```

## Coroutine

不要重新统计每一次 `goApp()`。

直接以 `CoroutineManager::getCoroutineStatus()` / Swoole stats 作为当前 Worker 状态来源。

必要的累计指标只记录：

```text
swoolefy_coroutine_created_total
swoolefy_coroutine_finished_total
```

## Pool

```text
swoolefy_pool_fetch_total
swoolefy_pool_release_total
swoolefy_pool_fetch_error_total
```

Pool 当前 active/idle 数量优先从实际 Pool 实现获取，不在 `RuntimeMetrics` 中维护第二份状态。

---

# 11. HTTP Metrics 最佳插入点

不要把 Metrics 放到 `HttpRoute`。

原因：

```text
HttpRoute
```

只覆盖 HTTP 路由请求，而 `HttpServer` 才是 HTTP 请求生命周期入口。

当前 `HttpServer` 已在 `request` callback 中处理：

- OpenTelemetry
- HeaderContext
- FrameworkContext
- `static::onRequest()`
- 异常
- response fallback

因此第一层 Metrics 应该放在 `HttpServer::request` 生命周期外层/内层合适位置，而不是 Controller。

建议：

```text
HttpServer::request
    │
    ├── requestStarted()
    │
    ├── OpenTelemetry
    │
    ├── onRequest()
    │
    └── finally
          ├── requestFinished()
          ├── duration()
          └── error()
```

注意 WorkerService / `/favicon.ico` 等特殊路径必须按现有行为处理，不应该强行统计为普通业务 HTTP 请求。

当前 HTTP 入口已经明确区分 WorkerService 和普通 HTTP，并在 WorkerService 无控制面时主动 503。citeturn8view3turn9view0

---

# 12. App 层 Metrics 插入点

如果希望统计真正的：

```text
App 请求生命周期
```

则 `App::run()` 是最准确的地方。

建议：

```php
public function run(...)
{
    $metrics = RuntimeMetrics::current();
    $start = hrtime(true);
    $metrics->requestStarted();

    try {
        // 原有代码完全保持
    } catch (\Throwable $throwable) {
        $metrics->requestError();
        // 原有异常处理
    } finally {
        $metrics->requestFinished();
        $metrics->requestDuration(
            (hrtime(true) - $start) / 1_000_000_000
        );

        // 原有 finally
    }
}
```

这里比在 Controller 埋点更准确，因为：

```text
Middleware
Controller
Exception
afterRequest
App::end
```

全部属于同一个生命周期。

但如果最终决定 HTTP 请求只统计一次，则**不要同时在 HttpServer 和 App::run() 两处统计**。

最终推荐：

> **业务请求 Metrics 放 `App::run()`；HTTP Server 层只负责 WorkerService/协议级异常，不重复计数。**

---

# 13. App::end() 不负责 Metrics Registry 生命周期

当前 `App::end()`：

```text
handleLog()
ZFactory::removeInstance()
pushComponentPools()
clearComponent()
Application::removeApp()
removeControllerInstance()
response->end()
```

citeturn7view0

所以 Metrics 不应该放进：

```php
ZFactory
Application
Context
Component containers
```

否则 `App::end()` 会把 Worker Metrics 一起清理掉。

正确模型：

```text
Worker
│
├── RuntimeMetrics       ← Worker 生命周期
├── MemoryMonitor        ← Worker 生命周期
├── MemoryHistory        ← Worker 生命周期
│
└── Request Coroutine
    ├── App
    ├── ZFactory
    ├── Context
    └── Component
```

---

# 14. RuntimeMetrics 的 Worker Registry 获取方式

建议新增：

```php
final class RuntimeRegistry
{
    private static ?MetricsRegistry $metrics = null;

    public static function init(): MetricsRegistry
    {
        return self::$metrics ??= new MetricsRegistry();
    }

    public static function metrics(): MetricsRegistry
    {
        return self::$metrics ??= new MetricsRegistry();
    }

    public static function reset(): void
    {
        self::$metrics = null;
    }
}
```

注意：

这个 static 是：

```text
Worker Process Local
```

不是跨进程共享。

Worker reload 后进程重新创建，数据自然清零。

这是符合 Swoolefy 当前 Worker 模型的。

---

# 15. WorkerStart 是 Runtime 初始化点

`BaseServer::workerStartInit()` 已经是明确的 WorkerStart 初始化入口，并负责：

- 设置 Swoole Server
- runtime coroutine
- include files
- shutdown function
- cache
- worker process name
- worker user/group
- worker PID 映射
- OpenTelemetry
- `startCtrl->workerStart()` / `onWorkerStart()`

citeturn10view0

因此 Runtime Observability 的 Worker 初始化必须进入这个生命周期，而不是在第一个 HTTP 请求时懒加载。

推荐：

```text
workerStartInit()
       │
       ├── 原有初始化
       │
       ├── RuntimeRegistry::init()
       ├── MemoryMonitor::start()
       └── 原有 workerStart callback
```

如果希望不直接修改 BaseServer，则更推荐放到现有：

```php
startCtrl->workerStart()
static::onWorkerStart()
```

对应的统一 Runtime bootstrap 中。

最终以代码中现有扩展点为准，避免硬编码到所有 Server 子类。

---

# 16. Worker Diagnostics

Diagnostics 的核心原则：

> **读取已有 Runtime 状态，不创建第二套 Runtime。**

最终 Snapshot：

```text
RuntimeDiagnostics
│
├── process
├── worker
├── server
├── coroutine
├── memory
├── metrics
└── pools
```

---

# 17. Process Snapshot

来源：`AbstractBaseWorker` 已经保存：

```text
pid
masterPid
processWorkerId
processName
processType
startTime
rebootCount
```

并且 WorkerStart 会维护 Worker ID → PID 的映射。citeturn3view2turn10view0

因此 Diagnostics 不应该再次通过：

```text
ps
shell_exec
exec
```

扫描进程。

优先直接读取 Worker 实例和 `BaseServer` 现有状态。

---

# 18. Server Snapshot

`BaseServer::getStats()` 已经直接返回：

```php
self::$server->stats();
```

citeturn10view0

因此：

```php
$serverStats = BaseServer::getStats();
```

作为 Diagnostics 的 server 数据源。

不要重新维护：

```text
connection_num
request_count
idle_worker_num
```

否则一定会出现两个来源不一致。

---

# 19. Coroutine Snapshot

当前 `CoroutineManager` 已提供：

```php
getCoroutineStatus()
listCoroutines()
getBackTrace()
```

citeturn8view2

所以 Diagnostics：

```php
$stats = CoroutineManager::getInstance()->getCoroutineStatus();
```

即可。

默认只输出：

```text
coroutine_num
coroutine_peak_num
coroutine_last_cid
```

具体字段必须以当前 Swoole 6.2 返回结果为准，不要在框架中写死不存在的字段。

---

# 20. Coroutine List / Backtrace 必须是按需诊断

不能每 5 秒自动：

```php
Coroutine::list();
Coroutine::getBackTrace(...);
```

原因：

```text
生产环境 Coroutine 数量可能非常大
```

因此：

```text
runtime:status
```

默认只输出 stats。

只有：

```text
runtime:diagnose --coroutines
runtime:diagnose --backtrace=<cid>
```

才获取详细数据。

这是生产级设计非常关键的一点。

---

# 21. Component / Pool Diagnostics

当前 `ComponentTrait` 已经区分：

```text
containers
componentPools
componentPoolsObjIds
```

citeturn8view0

Diagnostics 可以输出：

```json
{
  "pools": {
    "mysql": {
      "enabled": true,
      "component_loaded": true,
      "pooled_object": true
    }
  }
}
```

但不要把业务对象本身输出：

```php
'object' => $containerObject->__object
```

也不要调用：

```text
serialize(object)
var_dump(object)
Reflection object graph
```

否则 Diagnostics 自己可能导致内存暴涨。

---

# 22. Pool Metrics 的真正插入点

当前：

```php
if (in_array($name, $this->componentPools) && $cid >= 0) {
    $poolHandler = CoroutinePools::getInstance()->getPool($name);
    if (is_object($poolHandler)) {
        $this->containers[$name] = $poolHandler->fetchObj();
    }
}
```

以及：

```php
pushComponentPools()
    ↓
getPool($name)->pushObj($obj)
```

citeturn8view0

因此：

```text
fetch_total
release_total
```

必须直接在这里统计。

而不是在：

```text
Controller
Service
DB SDK
```

中统计。

这样才能覆盖所有组件池使用者。

---

# 23. Pool Metrics 必须避免对象泄漏

当前 `componentPoolsObjIds` 的作用非常重要：

> 防止非进程池对象被 push 回 Pool。

因此 Metrics 代码不能修改这个判断逻辑。

错误：

```php
$metrics->release($name);
$pool->pushObj($obj);
```

正确：

```php
if ($this->isPooledObject($obj)) {
    $pool->pushObj($obj);
    $metrics->poolReleased($name);
}
```

Metrics 必须是旁路观察者，不能成为资源归还条件。

---

# 24. Memory Leak Detection：先解决一个关键认知

`memory_get_usage(true)` 上升不等于 PHP 内存泄漏。

Swoole 常驻 Worker 中：

```text
PHP allocator
Zend memory manager
扩展内存
Swoole 内存
连接池
系统 RSS
```

并不是一个数字。

因此必须同时观察：

```text
PHP Usage
PHP Real Usage
PHP Peak
RSS
Coroutine Count
Request Count
Pool 状态
```

至少不能只根据：

```php
memory_get_usage()
```

判断泄漏。

---

# 25. MemorySnapshot

```php
final class MemorySnapshot
{
    public function __construct(
        public readonly int $timestamp,
        public readonly int $requestCount,
        public readonly int $phpUsage,
        public readonly int $phpRealUsage,
        public readonly int $phpPeakUsage,
        public readonly ?int $rss,
        public readonly int $coroutineNum,
    ) {
    }

    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'request_count' => $this->requestCount,
            'php_usage' => $this->phpUsage,
            'php_real_usage' => $this->phpRealUsage,
            'php_peak_usage' => $this->phpPeakUsage,
            'rss' => $this->rss,
            'coroutine_num' => $this->coroutineNum,
        ];
    }
}
```

---

# 26. 为什么必须同时保存 Usage 与 Real Usage

PHP：

```php
memory_get_usage(false)
```

表示当前 PHP 使用量。

```php
memory_get_usage(true)
```

表示从系统分配给 PHP allocator 的实际内存量。

可能出现：

```text
usage
100MB → 105MB → 100MB

real
128MB → 128MB → 128MB
```

这种情况下不能直接判定泄漏。

真正需要关注的是：

```text
经过大量请求后 baseline 是否持续抬高
```

---

# 27. RSS 采集

Linux Worker 推荐：

```text
/proc/{pid}/status
VmRSS
```

但这是增强信息，不应该作为 Swoolefy 核心功能的硬依赖。

实现：

```php
private function readRss(int $pid): ?int
{
    if (PHP_OS_FAMILY !== 'Linux') {
        return null;
    }

    $file = "/proc/{$pid}/status";

    if (!is_readable($file)) {
        return null;
    }

    $content = file_get_contents($file);

    if ($content === false) {
        return null;
    }

    if (!preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $content, $matches)) {
        return null;
    }

    return (int)$matches[1] * 1024;
}
```

这个读取操作只能放在：

```text
Timer
Diagnostics on-demand
```

不能放在每次 Request。

---

# 28. MemoryHistory 必须有硬上限

生产环境绝对不能：

```php
$this->history[] = $snapshot;
```

无限增长。

推荐：

```text
sample_interval = 10s
history_size = 360
```

即：

```text
360 * 10s = 1 hour
```

每个 Worker 只保存约 1 小时历史。

如果只是判断趋势，甚至：

```text
120 * 10s = 20 minutes
```

已经足够。

推荐默认：

```text
history_size = 180
sample_interval = 10s
```

即 30 分钟窗口。

---

# 29. MemoryHistory 推荐实现

```php
final class MemoryHistory
{
    /** @var list<MemorySnapshot> */
    private array $items = [];

    public function __construct(
        private readonly int $maxSize,
    ) {
        if ($maxSize < 10) {
            throw new \InvalidArgumentException('maxSize must be >= 10.');
        }
    }

    public function push(MemorySnapshot $snapshot): void
    {
        $this->items[] = $snapshot;

        if (count($this->items) > $this->maxSize) {
            array_shift($this->items);
        }
    }

    /** @return list<MemorySnapshot> */
    public function all(): array
    {
        return $this->items;
    }
}
```

如果 benchmark 证明 `array_shift()` 成为热点，再替换为 RingBuffer；第一版不要为了理论性能增加复杂度。

---

# 30. Memory Leak Detection 不能只比较第一点和最后一点

错误：

```text
100MB
200MB
100MB
210MB
100MB
```

简单：

```text
last - first
```

会产生错误判断。

正确应该分析：

```text
趋势
斜率
持续增长比例
窗口 baseline
```

---

# 31. 推荐 Leak Detection 算法

采用四层判断：

```text
第一层：样本数量
第二层：Baseline → Current
第三层：连续增长比例
第四层：RSS 是否同步增长
```

最终：

```text
NORMAL
OBSERVING
SUSPECTED
CRITICAL
```

---

# 32. 第一层：最小样本

例如：

```text
min_samples = 12
```

12 个采样以前：

```text
NORMAL
```

避免 Worker 刚启动时：

```text
10MB → 30MB → 50MB
```

直接误报。

---

# 33. 第二层：Baseline

不要永远拿 Worker 启动时作为 baseline。

因为启动阶段：

```text
autoload
component
route
config
cache
```

本身会增长。

建议：

```text
Warmup samples = 6
```

Warmup 后：

```text
baseline = median(warmup samples)
```

然后再开始检测。

---

# 34. 第三层：连续增长比例

例如窗口：

```text
100
102
110
109
120
130
129
140
```

并不是每次都增长，但总体趋势明显。

统计：

```text
positive_delta_count / total_delta_count
```

例如：

```text
>= 70%
```

才认为持续增长。

---

# 35. 第四层：RSS 交叉验证

### 情况 A

```text
PHP usage ↑
RSS ↑
```

强烈怀疑真实内存增长。

### 情况 B

```text
PHP usage ↑
RSS 不变
```

可能只是 allocator 行为。

### 情况 C

```text
PHP usage 不变
RSS ↑
```

需要怀疑扩展/Swoole/native memory。

因此：

> **Memory Leak Detector 必须区分 PHP memory growth 和 RSS growth。**

---

# 36. Leak Detector 状态机

```text
             ┌──────────────┐
             │    NORMAL    │
             └──────┬───────┘
                    │ growth
                    ▼
             ┌──────────────┐
             │  OBSERVING   │
             └──────┬───────┘
                    │ continuous growth
                    ▼
             ┌──────────────┐
             │  SUSPECTED   │
             └──────┬───────┘
                    │ critical threshold
                    ▼
             ┌──────────────┐
             │   CRITICAL   │
             └──────────────┘
```

恢复：

```text
SUSPECTED → NORMAL
```

如果后续窗口已经回落，不应该永久保持 SUSPECTED。

---

# 37. MemoryLeakDetector 代码设计

```php
final class MemoryLeakDetector
{
    public const NORMAL = 'normal';
    public const OBSERVING = 'observing';
    public const SUSPECTED = 'suspected';
    public const CRITICAL = 'critical';

    public function __construct(
        private readonly int $warningGrowthBytes,
        private readonly int $criticalMemoryBytes,
        private readonly float $positiveGrowthRatio = 0.7,
        private readonly int $minSamples = 12,
    ) {
    }

    public function detect(array $samples): string
    {
        if (count($samples) < $this->minSamples) {
            return self::NORMAL;
        }

        $last = $samples[array_key_last($samples)];

        if (
            $this->criticalMemoryBytes > 0
            && $last->phpRealUsage >= $this->criticalMemoryBytes
        ) {
            return self::CRITICAL;
        }

        $first = $samples[0];
        $growth = $last->phpRealUsage - $first->phpRealUsage;

        if ($growth <= 0) {
            return self::NORMAL;
        }

        if ($growth < $this->warningGrowthBytes) {
            return self::OBSERVING;
        }

        $positive = 0;
        $total = count($samples) - 1;

        for ($i = 1; $i < count($samples); ++$i) {
            if ($samples[$i]->phpRealUsage > $samples[$i - 1]->phpRealUsage) {
                ++$positive;
            }
        }

        if ($total <= 0) {
            return self::OBSERVING;
        }

        return ($positive / $total) >= $this->positiveGrowthRatio
            ? self::SUSPECTED
            : self::OBSERVING;
    }
}
```

生产版还应加入 RSS 交叉判断；上述代码表达核心状态机，最终实现以 `MemoryTrend` 为中间对象。

---

# 38. MemoryTrend

```php
final class MemoryTrend
{
    public function __construct(
        public readonly int $baseline,
        public readonly int $current,
        public readonly int $peak,
        public readonly int $growth,
        public readonly float $growthRate,
        public readonly float $positiveGrowthRatio,
        public readonly ?int $rssGrowth,
        public readonly string $state,
    ) {
    }
}
```

Diagnostics 输出的不是内部 History 全量，而是 Trend。

---

# 39. Worker Memory Timer

当前 Worker 已经存在 Timer 管理、Master live timer、reboot timer 等机制，因此 Memory Monitor 不应随意直接散落 `Swoole\Timer::tick()`。

推荐：

```text
WorkerStart
   ↓
RuntimeMonitor::start()
   ↓
TickManager / 现有 Timer 管理体系
   ↓
MemoryMonitor::sample()
```

如果当前 TickManager API 无法合理复用，再使用单独 `Swoole\Timer::tick()`，但必须保存 timer ID，并在 WorkerStop/WorkerExit 清理。

---

# 40. Memory Timer 必须避免和已有 Timer 冲突

当前 `AbstractBaseWorker` 已经存在：

- Master live timer
- reboot timer
- exit timer
- Cron timer
- SysProcess timer

citeturn3view2turn14view0

因此不要让 Memory Monitor：

```text
修改已有 timer
复用已有 timer ID
clear 所有 timer
```

只能管理自己的：

```php
private ?int $memoryTimerId = null;
```

---

# 41. WorkerStop / WorkerExit 清理

`HttpServer` 已有：

```text
WorkerStop
WorkerExit
WorkerError
```

并且 WorkerStop/WorkerExit 会通过 Coroutine/EventApp 调用现有控制器。citeturn9view0

Memory Monitor 必须在 Worker 退出前清理：

```php
public function stop(): void
{
    if ($this->memoryTimerId !== null) {
        \Swoole\Timer::clear($this->memoryTimerId);
        $this->memoryTimerId = null;
    }

    $this->history->clear();
}
```

注意：

> **不能依赖 Worker 进程退出自动清理。**

这是为了避免 WorkerStop → WorkerExit 之间仍有 Timer 回调执行。

---

# 42. Diagnostics 不应该自动执行 Memory History 全量输出

默认：

```json
{
  "memory": {
    "state": "normal",
    "usage": 134217728,
    "real_usage": 150994944,
    "rss": 181403648,
    "growth": 10485760
  }
}
```

只有调试模式：

```text
runtime:diagnose --memory-history
```

才输出：

```json
{
  "samples": [
    ...
  ]
}
```

避免一个 Diagnostics 请求返回数 MB 数据。

---

# 43. RuntimeDiagnostics 最终代码结构

```php
final class RuntimeDiagnostics
{
    public function snapshot(bool $detail = false): array
    {
        return [
            'runtime' => $this->runtime(),
            'process' => $this->process(),
            'worker' => $this->worker(),
            'server' => $this->server(),
            'coroutine' => $this->coroutine(),
            'memory' => $this->memory($detail),
            'metrics' => $this->metrics(),
            'pool' => $this->pool(),
        ];
    }
}
```

所有 collector 都应该是只读。

---

# 44. Runtime Collector 接口

为了避免 Diagnostics 变成一个巨大的类：

```php
interface DiagnosticCollectorInterface
{
    public function collect(): array;
}
```

实现：

```text
RuntimeCollector
ProcessCollector
WorkerCollector
ServerCollector
CoroutineCollector
MemoryCollector
MetricsCollector
PoolCollector
```

但是：

> 第一版不要为了 7 个简单 Collector 引入复杂 DI。

可以先由 `RuntimeDiagnostics` 直接调用现有对象；只有代码确实膨胀后再拆 Collector。

这是 Swoolefy 当前“实用主义优先”的架构风格更合适的做法。

---

# 45. Runtime Snapshot 数据结构

建议：

```json
{
  "runtime": {
    "php_version": "8.4.x",
    "swoole_version": "6.2.x",
    "swoolefy_version": "6.2.x",
    "server_protocol": "http"
  },
  "process": {
    "pid": 12345,
    "parent_pid": 1234
  },
  "worker": {
    "worker_id": 2,
    "worker_num": 8,
    "uptime": 3600,
    "reboot_count": 0
  },
  "server": {},
  "coroutine": {},
  "memory": {},
  "metrics": {},
  "pool": {}
}
```

---

# 46. 不要输出敏感信息

Diagnostics 禁止输出：

```text
.env
DB password
Redis password
JWT secret
API Key
Authorization
Cookie
Request Body
Nacos password
完整 application.yaml
```

尤其不能：

```php
'config' => BaseServer::getConf()
```

这会把配置中心、Redis、数据库等敏感配置全部暴露。

---

# 47. Diagnostics HTTP 接口设计

Swoolefy 当前已经有 WorkerService HTTP control plane / `CtlApi`。

`CtlApi` 已有：

```text
/process-list
/process-start
/process-stop
/process-status
/master-server-restart
```

citeturn12view1

因此，目标形态是复用既有 control plane 的：

```text
GET /process-runtime?process_name=xxx
```

而不是在普通应用路由中新增：

```text
/runtime
/diagnostics
/metrics/status
```

但该接口不是无条件的本阶段交付物。只有满足以下条件时，才可以在 `CtlApi` 注册：

1. 能通过现有进程状态/管道机制取得 `process_name` 所选 Worker 的真实 `RuntimeDiagnostics::snapshot()`；不能返回承载 `CtlApi` 请求的 control-plane Worker 自身快照。
2. 能复用既有 control-plane 访问控制，并完成认证、授权及运维审计；仅有 Cron/Daemon + HTTP 的入口判定不等于认证。
3. `diagnostics.enable = false` 时仅禁用新接口，不影响既有 `/process-status` 与进程控制接口。

当前代码尚未具备第一、二项：`CtlApi` 只读取 `WORKER_STATUS_FILE` 中已有的进程状态，`RuntimeRegistry` 则是当前 PHP Worker 本地静态状态；两者之间没有按 `process_name` 获取完整诊断快照的传输协议。同时，现有 `CtlApi` 没有框架内建的认证/授权检查。因此本阶段不注册 `/process-runtime`，优先保留 CLI/受保护的应用层适配作为后续实现方向。

---

# 48. CLI 优先

建议新增：

```bash
php cli.php runtime:status
```

```bash
php cli.php runtime:diagnose
```

```bash
php cli.php runtime:diagnose --memory
```

```bash
php cli.php runtime:diagnose --coroutines
```

```bash
php cli.php runtime:diagnose --backtrace=123
```

但具体 Command 注册必须复用当前 `CommandRunner` / CLI 体系，不新增独立 CLI Parser。

---

# 49. Worker Runtime Diagnostics 与现有 CtlApi 的边界

`CtlApi`：

```text
控制 Worker
查看进程运行状态
start/stop/restart
```

RuntimeDiagnostics：

```text
只读 Worker 内部运行状态
```

所以：

```text
CtlApi
   │
   ├── process control
   └── process status

RuntimeDiagnostics
   │
   ├── memory
   ├── coroutine
   ├── server stats
   ├── pool
   └── metrics
```

不要让 Diagnostics 调用：

```text
restart
stop
kill
```

---

# 50. 与 SysProcess 的关系

当前 `SysProcess` 已经存在周期采集机制：

```text
tick
 ↓
callback
 ↓
request PV
 ↓
UDP / Swoole Redis / PhpRedis / File
```

citeturn12view0

因此不能重新设计：

```text
MetricsExporterProcess
```

来做相同事情。

正确方案：

```text
RuntimeMetrics
       │
       ▼
Worker-local runtime data
       │
       ├── Diagnostics
       │
       └── SysProcess / existing exporter
```

---

# 51. SysProcess 与 Runtime Metrics 的数据边界

SysProcess 适合：

```text
周期上报
跨进程/跨机器传输
```

RuntimeMetrics 适合：

```text
当前 Worker 内部实时状态
```

因此：

```text
RuntimeMetrics 不负责 UDP
RuntimeMetrics 不负责 Redis
RuntimeMetrics 不负责 File IO
```

否则 HTTP 请求路径会被监控 IO 污染。

---

# 52. Metrics Export 不要放 Request Path

错误：

```text
Request
 ↓
metrics
 ↓
json_encode
 ↓
redis publish
 ↓
response
```

正确：

```text
Request
 ↓
Counter++
 ↓
response

Worker Timer
 ↓
snapshot
 ↓
SysProcess / exporter
```

---

# 53. Worker Memory Leak Detection 与现有自动重启机制的关系

Swoolefy 已经存在 Worker lifeTime / reboot 机制；`AbstractBaseWorker::registerTickReboot()` 可以根据 lifeTime 定时 reboot。citeturn14view0

因此本次 Memory Leak Detection **不能直接接管 reboot**。

错误：

```text
Memory > threshold
↓
reboot()
```

正确：

```text
MemoryDetector
↓
CRITICAL
↓
Event / Log / Diagnostics
```

后续如果要做 Worker Protection，应该另立模块：

```text
WorkerProtection
```

而不是污染 MemoryLeakDetector。

---

# 54. Worker Memory Detection 应关联 Request Count

一个非常重要的生产指标：

```text
memory_growth / request_count
```

例如：

```text
Worker 启动
100MB

处理 1 万请求
120MB

处理 10 万请求
125MB
```

这和：

```text
100MB
处理 10 个请求
125MB
```

完全不同。

因此 MemorySnapshot 必须记录：

```text
request_count
```

而不是只保存 timestamp。

---

# 55. 最佳 Leak 分析模型

建议使用：

```text
X = request_count
Y = memory
```

观察：

```text
memory(request_count)
```

而不是单纯：

```text
memory(time)
```

因为 Worker 是请求驱动的常驻进程。

最终 Trend 可以保存：

```text
requests_delta
memory_delta
memory_per_1k_requests
```

例如：

```json
{
  "requests_delta": 100000,
  "memory_delta": 8388608,
  "memory_per_1k_requests": 83886
}
```

这比单纯 `+8MB` 更有诊断价值。

---

# 56. Coroutine 泄漏检测

Memory Leak Detection 不能只看 Memory。

如果：

```text
coroutine_num
```

长期上涨：

```text
10
20
30
100
500
```

而且没有回落，很可能是：

```text
协程没有结束
channel 没关闭
timer callback 持有对象
```

因此 Memory Trend 应同时记录：

```text
coroutine_num
```

但不要在第一版实现复杂的 Coroutine Leak Detector。

只提供关联数据即可。

---

# 57. Pool 泄漏检测

同理：

```text
Pool fetch
Pool release
```

如果：

```text
fetch = 100000
release = 99990
```

长期差异扩大，则可以提示：

```text
pool_resource_balance_warning
```

但本次第一版不自动判定 Pool Leak，只输出：

```text
fetch_total
release_total
balance
```

---

# 58. 最终 Diagnostics 输出

```json
{
  "worker": {
    "pid": 12345,
    "worker_id": 2,
    "uptime_seconds": 86400,
    "reboot_count": 0
  },
  "request": {
    "total": 1234567,
    "active": 8,
    "errors": 123,
    "avg_duration_ms": 8.32
  },
  "coroutine": {
    "num": 23,
    "last_cid": 120034
  },
  "memory": {
    "usage": 134217728,
    "real_usage": 150994944,
    "peak": 180355072,
    "rss": 187432960,
    "growth": 8388608,
    "memory_per_1k_requests": 6815,
    "state": "normal"
  },
  "pool": {
    "redis": {
      "fetch_total": 123000,
      "release_total": 122999,
      "fetch_error_total": 1,
      "balance": 1
    }
  }
}
```

---

# 59. Metrics Snapshot 与 Diagnostics Snapshot 分离

不要：

```php
RuntimeDiagnostics::snapshot()
```

直接把 MetricsRegistry 内部对象返回。

应该：

```text
MetricsRegistry
   ↓
snapshot()
   ↓
array
   ↓
Diagnostics
```

这样 Metrics 内部以后可以替换，而 Diagnostics API 不变。

---

# 60. 配置设计

建议使用现有配置体系，不增加独立配置文件。

示例：

```php
'runtime_observability' => [
    'metrics' => [
        'enable' => true,
    ],

    'diagnostics' => [
        'enable' => true,
    ],

    'memory' => [
        'enable' => true,
        'sample_interval' => 10000,
        'history_size' => 180,
        'warmup_samples' => 6,
        'warning_growth' => 64 * 1024 * 1024,
        'critical_memory' => 0,
        'min_samples' => 12,
    ],
],
```

---

# 61. 配置默认值

生产推荐：

```text
metrics.enable = true
diagnostics.enable = true
memory.enable = true

sample_interval = 10s
history_size = 180
warmup_samples = 6
warning_growth = 64MB
critical_memory = 0
min_samples = 12
```

为什么 `critical_memory = 0`？

因为不同容器/机器内存上限完全不同。

例如：

```text
512MB container
8GB server
```

使用相同绝对值并不合理。

第一版 Critical 更建议由部署配置显式设置。

---

# 62. Metrics enable=false 的要求

关闭 Metrics 后：

```text
不创建 Registry
不执行 Counter
不执行 Histogram
不增加 Request Timer
```

即：

```php
if (!$runtimeMetrics->isEnabled()) {
    return;
}
```

并尽量让 disabled path 只产生一次配置判断。

---

# 63. Diagnostics enable=false

关闭时：

```text
若后续注册 /process-runtime，则禁止该接口
禁止 runtime:diagnose
```

但不能影响：

```text
现有 /process-status
现有 process control
```

向后兼容必须保留。

---

# 64. Memory enable=false

关闭后：

```text
不创建 Timer
不创建 History
不读取 /proc
```

Diagnostics 的 memory：

```json
{
  "enabled": false
}
```

不要返回伪造的 0。

---

# 65. RuntimeMetrics API

建议：

```php
final class RuntimeMetrics
{
    public function requestStarted(): void;

    public function requestFinished(): void;

    public function requestError(): void;

    public function requestDuration(float $seconds): void;

    public function poolFetched(string $name): void;

    public function poolReleased(string $name): void;

    public function poolFetchError(string $name): void;

    public function snapshot(): array;
}
```

不要把底层 Registry 暴露给业务代码。

---

# 66. 不允许业务代码随意注册 Metrics

第一版不开放：

```php
Application::getApp()->metrics()->counter(...)
```

因为这会让业务开发者随意产生：

```text
动态指标
动态 key
高 cardinality
```

框架内部先固定指标。

未来如需开放 Custom Metrics，再单独设计命名规范。

---

# 67. RuntimeDiagnostics API

```php
final class RuntimeDiagnostics
{
    public function snapshot(): array;

    public function memory(): array;

    public function coroutine(): array;

    public function server(): array;

    public function process(): array;
}
```

但对外推荐只暴露：

```php
snapshot()
```

内部方法用于测试。

---

# 68. RuntimeRegistry 生命周期

Worker Start：

```text
RuntimeRegistry::init()
```

Worker Stop：

```text
RuntimeRegistry::reset()
```

HTTP Request：

```text
只读取 Worker Registry
```

绝对禁止：

```text
Request 创建 Registry
Request reset Registry
```

---

# 69. 为什么不放 ZFactory

当前：

```php
ZFactory::getInstance($class)
```

按 Coroutine ID 管理。citeturn7view1

如果把 Metrics 放进去：

```text
Request A → Metrics A
Request B → Metrics B
```

最终失去 Worker 累计能力。

而我们需要的是：

```text
Worker
 ├── Request 1
 ├── Request 2
 ├── Request 3
 └── Metrics total
```

所以不能进入 ZFactory。

---

# 70. 为什么不放 Context

Context 的职责是：

```text
请求/协程上下文
```

例如：

```text
trace_id
user
locale
header propagation
```

当前 Context 还明确禁止把对象、连接、Closure 放入可传播快照。citeturn7view4

Metrics 属于 Worker Runtime 状态，因此不应该放 Context。

---

# 71. 为什么不放 Component

Component 是：

```text
App / Request 生命周期
```

当前 App::end() 会：

```text
clearComponent
```

citeturn7view0turn8view0

如果 Metrics 是 Component：

```text
请求结束
↓
clearComponent
↓
Metrics 清空
```

这是错误的。

---

# 72. 为什么 Worker 是最合适的归属

Worker 本身就是：

```text
常驻进程
```

Metrics 和 Memory Leak Detection 的生命周期恰好是：

```text
WorkerStart
    ↓
长期运行
    ↓
WorkerStop
```

与 Swoolefy 的 Worker 模型完全一致。

---

# 73. 与 Worker 动态进程的关系

Swoolefy 支持静态和动态 Worker。

`AbstractBaseWorker` 中已有：

```text
PROCESS_STATIC_TYPE
PROCESS_DYNAMIC_TYPE
```

citeturn3view2

每一个 Worker Process 都应该拥有独立：

```text
MetricsRegistry
MemoryHistory
MemoryDetector
```

不能跨 Worker 共享。

---

# 74. Worker reboot 后 Metrics 如何处理

```text
Worker A
  ↓
metrics = 100000
memory = 300MB
  ↓
reboot
  ↓
Worker B
  ↓
metrics = 0
memory baseline = new
```

这是正确的。

Diagnostics 必须输出：

```text
worker_id
pid
start_time
reboot_count
```

让用户知道：

```text
这是新 Worker
```

---

# 75. Memory Detection 与 Worker reboot 的结合

不要把历史跨 reboot 保存到 Redis。

否则：

```text
Worker A memory 300MB
↓
reboot
↓
Worker B memory 80MB
```

可能错误判断：

```text
memory suddenly dropped
```

第一版 History 必须 Worker-local。

---

# 76. 如果需要跨 Worker/跨 reboot 趋势怎么办

交给已有外部采集链路：

```text
SysProcess
UDP
Redis
File
OpenTelemetry
```

而不是把 MemoryHistory 设计成持久化数据库。

---

# 77. Runtime Metrics 与 OpenTelemetry 的关系

当前框架已经存在 OpenTelemetry HTTP instrumentation。citeturn8view3

因此：

```text
Metrics
```

负责：

```text
Counter
Gauge
Worker Runtime
```

OpenTelemetry：

```text
Trace
Span
Trace Context
```

不要在 Metrics 模块中重复实现：

```text
trace_id
span_id
trace propagation
```

---

# 78. Request Trace 与 Metrics 可以关联，但不要存 Trace ID

例如日志：

```text
request error
trace_id=xxx
```

可以由现有 Trace/Context 完成。

Metrics 只计：

```text
errors_total++
```

不要：

```php
$metrics->errors[$traceId] = ...;
```

否则 Metrics 会变成内存泄漏源。

---

# 79. 生产级错误隔离

Observability 不能影响业务。

例如：

```php
try {
    $metrics->requestFinished();
} catch (\Throwable $e) {
    BaseServer::catchException($e);
}
```

但是更推荐 Metrics 内部设计成：

```text
不抛异常
不 IO
不访问业务对象
```

让正常路径无需 try/catch 包裹。

Diagnostics 则可以抛异常，由 CLI/API 层处理。

---

# 80. Memory Timer 异常隔离

Memory Timer 必须：

```php
try {
    $snapshot = $monitor->sample();
    $history->push($snapshot);
    $trend = $detector->detect($history);
} catch (\Throwable $e) {
    BaseServer::catchException($e);
}
```

不能因为：

```text
/proc unavailable
Coroutine stats error
Memory collector error
```

导致 Worker 退出。

---

# 81. Diagnostics 异常策略

Diagnostics 是运维工具。

如果某一个 collector 失败：

不要整个 Snapshot 失败。

应该：

```json
{
  "memory": {
    "error": "collector_unavailable"
  },
  "coroutine": {
    "num": 10
  }
}
```

而不是：

```text
500 Internal Server Error
```

整个诊断系统不可用。

---

# 82. Collector 错误不能返回异常堆栈

生产 Diagnostics：

错误：

```json
{
  "trace": "..."
}
```

正确：

```json
{
  "error": "collector_failed"
}
```

详细异常写当前 Swoolefy Logger。

---

# 83. Metrics 内存安全

指标数量必须固定：

```text
Counter < 50
Gauge < 50
Histogram < 20
```

不要动态生成：

```text
metric[route]
metric[user]
metric[exception]
```

这是 Runtime Metrics 自身防泄漏的第一原则。

---

# 84. Memory Leak Detection 自身不能成为 Leak

必须保证：

```text
History size fixed
Snapshot object fixed size
No request object reference
No Controller reference
No App reference
No Closure capture request
```

尤其 Timer Closure 不得 capture：

```php
$request
$response
$controller
$app
```

只 capture：

```text
MemoryMonitor
MemoryHistory
MemoryDetector
```

---

# 85. Timer Closure 设计

推荐：

```php
$this->memoryTimerId = Timer::tick(
    $interval,
    function (): void {
        $this->sampleMemory();
    }
);
```

对象是 Worker Runtime 对象。

不要：

```php
Timer::tick($interval, function () use ($request) {});
```

---

# 86. Memory Sampling 采样内容

每次采样：

```text
current timestamp
request total
php usage
php real usage
php peak
rss
coroutine num
```

不采集：

```text
all coroutine backtrace
all object list
all components
all config
```

---

# 87. Memory Trend 输出

```php
[
    'baseline' => 104857600,
    'current' => 142606336,
    'peak' => 150994944,
    'growth' => 37748736,
    'growth_rate' => 0.36,
    'positive_growth_ratio' => 0.78,
    'rss_growth' => 44040192,
    'state' => 'suspected',
]
```

---

# 88. Warning / Critical 的建议

不要使用单一：

```text
+64MB
```

作为唯一条件。

推荐：

```text
WARNING:
    growth >= warning_growth
    AND positive_growth_ratio >= 0.7

CRITICAL:
    php_real_usage >= critical_memory
    OR rss >= critical_rss
```

其中 `critical_rss` 可以配置为 0 表示关闭。

---

# 89. Memory Detector 不做 GC 强制操作

不要：

```php
gc_collect_cycles();
```

然后根据 GC 后内存判断。

原因：

- 改变业务 Runtime
- 增加 CPU
- 影响诊断真实性

Detector 只观察，不主动修复。

---

# 90. Memory Detector 不调用 gc_status() 高频采集

`gc_status()` 可以用于更深度诊断，但不是第一版高频指标。

如果以后增加：

```text
gc_runs
gc_collected
gc_threshold
```

应该放 Diagnostics detail。

---

# 91. Worker Diagnostics 与 Process Status 的一致性

当前 Worker Process Status 已经可以通过 pipe：

```text
process::worker::action::status
```

请求 Worker 生成：

```text
memory
coroutine_num
```

然后写回 Master。citeturn3view2

因此新 Diagnostics 不应该重新发明：

```text
Worker → Master → status file
```

可以复用这个已有状态通道作为跨进程 Worker 状态来源。

---

# 92. 非 HTTP Worker 的 Diagnostics

Swoolefy 不只有 HTTP：

```text
WebSocket
UDP
TCP/RPC
MQTT
Cron
Daemon
Script
```

因此 Runtime Diagnostics 核心层必须协议无关。

```text
Core/Runtime
```

不能依赖：

```php
Swoole\Http\Request
```

HTTP Metrics 可以是一个 Adapter。

---

# 93. HTTP Metrics 是 Adapter，不是 Runtime 核心

结构：

```text
Core/Runtime/Metrics
        ↑
        │
HTTP Adapter
WebSocket Adapter
Worker Adapter
```

第一版可以只接 HTTP + Worker + Pool。

未来其他协议自然接入。

---

# 94. 代码目录最终建议

```text
src/Core/Runtime/
├── RuntimeRegistry.php
│
├── Metrics/
│   ├── Counter.php
│   ├── Gauge.php
│   ├── Histogram.php
│   ├── MetricsRegistry.php
│   ├── RuntimeMetrics.php
│   └── MetricSnapshot.php
│
├── Diagnostics/
│   ├── RuntimeDiagnostics.php
│   └── DiagnosticSnapshot.php
│
└── Memory/
    ├── MemoryMonitor.php
    ├── MemorySnapshot.php
    ├── MemoryHistory.php
    ├── MemoryTrend.php
    └── MemoryLeakDetector.php
```

---

# 95. 不建议新增这些目录

不要：

```text
src/Monitor/
src/Monitoring/
src/APM/
src/Prometheus/
src/Observability/
```

因为 Swoolefy 已经有 Core Runtime 体系。

`Runtime` 放在 `Core` 下更符合现有结构。

---

# 96. RuntimeRegistry 最终代码

```php
final class RuntimeRegistry
{
    private static ?MetricsRegistry $metrics = null;
    private static ?MemoryMonitor $memory = null;
    private static ?RuntimeDiagnostics $diagnostics = null;

    public static function initialize(array $config): void
    {
        if (($config['metrics']['enable'] ?? true) === true) {
            self::$metrics = new MetricsRegistry();
        }

        if (($config['memory']['enable'] ?? true) === true) {
            self::$memory = new MemoryMonitor($config['memory']);
        }

        self::$diagnostics = new RuntimeDiagnostics();
    }

    public static function metrics(): ?MetricsRegistry
    {
        return self::$metrics;
    }

    public static function memory(): ?MemoryMonitor
    {
        return self::$memory;
    }

    public static function diagnostics(): ?RuntimeDiagnostics
    {
        return self::$diagnostics;
    }

    public static function reset(): void
    {
        self::$diagnostics = null;
        self::$memory = null;
        self::$metrics = null;
    }
}
```

生产实现应增加：

```text
double initialize protection
shutdown
config normalization
```

但不要引入复杂容器。

---

# 97. WorkerStart 集成伪代码

```php
protected function workerStartInit($server, $workerId)
{
    // existing bootstrap

    RuntimeBootstrap::workerStart(
        server: $server,
        workerId: $workerId,
    );

    // existing workerStart
}
```

RuntimeBootstrap：

```php
final class RuntimeBootstrap
{
    public static function workerStart($server, int $workerId): void
    {
        $config = BaseServer::getConf()['runtime_observability'] ?? [];

        RuntimeRegistry::initialize($config);

        RuntimeRegistry::memory()?->start();
    }
}
```

最终是否直接新增 `RuntimeBootstrap`，要看现有 `EventHandler/startCtrl` 扩展点；如果已有统一 bootstrap，则优先复用，不要新增这个类。

---

# 98. WorkerStop 集成伪代码

```php
RuntimeRegistry::memory()?->stop();
RuntimeRegistry::reset();
```

顺序：

```text
Stop Memory Timer
↓
最后一次 Snapshot（可选）
↓
清空 History
↓
Registry reset
```

如果需要最后一次状态，必须在 Timer clear 后立即采集一次，防止并发 Timer 回调。

---

# 99. App::run() 最终 Metrics 代码

建议只修改最少代码：

```php
public function run(...)
{
    $runtimeMetrics = RuntimeRegistry::metrics();
    $startNs = hrtime(true);

    $runtimeMetrics?->requestStarted();

    try {
        // 原有 App::run() 代码
    } catch (\Throwable $throwable) {
        $runtimeMetrics?->requestError();
        // 原有处理
    } finally {
        $runtimeMetrics?->requestDuration(
            (hrtime(true) - $startNs) / 1_000_000_000
        );
        $runtimeMetrics?->requestFinished();

        // 原有 finally 完整保留
    }
}
```

关键：

> Metrics 代码必须放在原有 try/finally 周围，不改变原有异常处理和 App::end() 顺序。

---

# 100. App::end() 不建议增加大量 Metrics 代码

只需要：

```text
requestFinished
```

如果 `requestFinished` 已在 `run()->finally` 调用，则 `end()` 不再调用。

否则：

```text
run finally
    ↓
end
```

很容易 double count。

因此推荐：

```text
requestStarted
    ↓
run
    ↓
requestFinished
    ↓
end
```

而不是：

```text
requestStarted
    ↓
run
    ↓
end
    ↓
requestFinished
```

因为 `end()` 在 defer 场景下可能被执行。

---

# 101. 关于 App::end() 多次调用

当前 `App` 已有：

```php
protected bool $isEnd = false;
```

但当前 `end()` 本身并没有使用 `$isEnd` 作为完整幂等保护；用户已经明确确认：当前阶段 **不需要修改 App::end() 的多次清空行为**。

因此本方案：

> **不修改 App::end() 幂等语义。**

Metrics 必须自行保证不会因为 `end()` 多次调用而重复统计。

最安全的方法是：

```text
requestStarted
↓
App::run finally
↓
requestFinished
```

而不是把 Metrics 完全绑定到 `end()`。

---

# 102. Request Duration 必须使用 hrtime()

不要：

```php
microtime(true)
```

作为生产级耗时计时器。

推荐：

```php
$start = hrtime(true);
$duration = hrtime(true) - $start;
```

转换：

```php
$seconds = $duration / 1_000_000_000;
```

因为 `hrtime()` 使用单调时钟，更适合计算 duration。

---

# 103. HTTP Error 的定义

不能只统计 Throwable。

需要区分：

```text
业务抛异常
HTTP 500
HTTP 404
HTTP 400
```

第一版推荐：

```text
errors_total = 5xx / uncaught application errors
```

不要把 404 默认算成系统错误。

如果以后需要 status code histogram，再增加固定 bucket。

---

# 104. 404 是否算 Error

建议：

```text
4xx = request outcome
5xx = server error
```

所以：

```text
http_requests_total
http_5xx_total
```

而不是：

```text
http_errors_total
```

把 404/401/403 全混在一起。

---

# 105. 推荐 HTTP 指标最终集合

```text
swoolefy_http_requests_total
swoolefy_http_requests_active
swoolefy_http_5xx_total
swoolefy_http_request_duration_seconds
```

如果要 status code：

```text
2xx
3xx
4xx
5xx
```

固定 4 个 Counter，不按 code 动态创建。

---

# 106. Pool Metrics 的指标集合

```text
swoolefy_pool_fetch_total
swoolefy_pool_release_total
swoolefy_pool_fetch_error_total
```

Pool 名称需要区分时：

```text
mysql
redis
curl
```

必须使用 Worker 启动时 `component_pools` 的固定别名白名单；未知、空白或运行中才出现的
别名只增加固定的 `swoolefy_pool_unattributed_total`，不能创建动态键，也不能归属到其他池。

不要：

```text
pool[arbitrary-user-input]
```

---

# 107. Runtime Metrics 输出格式

建议：

```json
{
  "counter": {
    "swoolefy_http_requests_total": 100000
  },
  "gauge": {
    "swoolefy_http_requests_active": 8
  },
  "histogram": {
    "swoolefy_http_request_duration_seconds": {
      "count": 100000,
      "sum": 812.4,
      "min": 0.001,
      "max": 2.3,
      "avg": 0.008124
    }
  }
}
```

---

# 108. 不直接兼容 Prometheus exposition format

第一版不需要把：

```text
# HELP
# TYPE
```

写进 Runtime Metrics。

内部格式保持 PHP array。

未来如果需要 Prometheus：

```text
MetricsRegistry
      ↓
PrometheusExporter
```

这是 Adapter 问题。

---

# 109. Benchmark 要求

至少测试：

```text
baseline
metrics enabled
metrics + memory enabled
```

比较：

```text
RPS
P50
P95
P99
CPU
Worker RSS
```

目标不是“0 开销”，而是：

```text
metrics path 只有几个整数操作
memory sampling 不在 request path
```

---

# 110. Request Path 禁止操作

在 HTTP Request 热路径中禁止：

```text
file_get_contents(/proc)
json_encode(snapshot)
Redis publish
UDP send
Reflection
Coroutine::list()
Coroutine::getBackTrace()
```

允许：

```text
Counter++
Gauge++
Histogram observe
hrtime
```

---

# 111. Diagnostics Path 可以做重操作

因为 Diagnostics 是按需调用：

```text
Coroutine::list()
/proc
server->stats()
Pool status
```

可以执行。

但是：

```text
backtrace
```

必须显式开启。

---

# 112. Memory Leak Detection 的测试场景

## 场景 A：正常 allocator

```text
100
120
100
125
105
```

结果：

```text
NORMAL / OBSERVING
```

## 场景 B：持续增长

```text
100
110
120
130
140
150
160
```

结果：

```text
SUSPECTED
```

## 场景 C：临界值

```text
512MB
```

结果：

```text
CRITICAL
```

## 场景 D：RSS 不同步

```text
PHP usage ↑
RSS ≈ stable
```

不能直接判定 native leak。

## 场景 E：Coroutine 持续增长

```text
10
20
30
40
```

Diagnostics 必须能看到关联数据。

---

# 113. Metrics 单元测试

```php
public function testCounter(): void
{
    $counter = new Counter();

    $counter->increment();
    $counter->increment(2);

    self::assertSame(3, $counter->value());
}
```

```php
public function testGauge(): void
{
    $gauge = new Gauge();

    $gauge->increment(10);
    $gauge->decrement(3);

    self::assertSame(7, $gauge->value());
}
```

---

# 114. App Metrics 集成测试

必须测试：

```text
正常请求
Controller Throwable
Middleware Throwable
Exception Response
catchAll
afterRequest
defer
```

验证：

```text
requests_total +1
active 最终 0
duration +1
5xx +1（适用时）
```

尤其：

> `App::end()` 多次清理不能造成 Metrics double count。

---

# 115. Pool 测试

必须覆盖：

```text
fetch 成功
fetch 失败
push 成功
非池对象
pushComponentPools
__unset
```

验证：

```text
只有真正 pooled object 才 release
```

这是对当前 `componentPoolsObjIds` 设计的回归保护。citeturn8view0

---

# 116. Diagnostics 测试

验证：

```text
runtime
process
worker
server
coroutine
memory
metrics
pool
```

任意一个 collector 失败时：

```text
其他 collector 仍然可用
```

---

# 117. Worker 生命周期测试

模拟：

```text
WorkerStart
 ↓
Runtime init
 ↓
Timer start
 ↓
WorkerStop
 ↓
Timer clear
 ↓
Runtime reset
```

验证：

```text
没有 Timer 残留
没有 History 残留
没有 Registry 残留
```

---

# 118. Memory History 测试

```text
max_size = 10
push 100 samples
```

最终：

```text
count = 10
```

必须验证最早数据已经淘汰。

---

# 119. Memory Detector 测试

测试：

```text
samples < min_samples
```

应该：

```text
NORMAL
```

测试：

```text
growth > threshold
positive ratio < 0.7
```

应该：

```text
OBSERVING
```

测试：

```text
growth > threshold
positive ratio >= 0.7
```

应该：

```text
SUSPECTED
```

---

# 120. 不能用真实内存泄漏测试替代单元测试

真实内存增长测试应该属于：

```text
Integration / Stress Test
```

而不是 Unit Test。

Unit Test 应直接构造：

```text
MemorySnapshot[]
```

模拟趋势。

---

# 121. Stress Test

设计一个 Worker：

```text
每请求创建临时对象
处理
释放
```

运行：

```text
100k
500k
1m requests
```

观察：

```text
PHP usage
RSS
Coroutine
Pool balance
```

最终确认：

```text
Memory Detector 不误报
```

---

# 122. Leak Simulation

测试真正泄漏：

```php
static $leaks = [];
$leaks[] = str_repeat('x', 1024);
```

每请求增加 1KB。

预期：

```text
NORMAL
↓
OBSERVING
↓
SUSPECTED
```

---

# 123. Native Memory Simulation

如果后续需要测试 RSS-only growth，可以通过扩展/外部资源模拟。

但第一版不强制构建 native extension 测试。

只验证：

```text
php usage
rss
```

两个指标可以独立存在。

---

# 124. 生产日志

只有状态变化才记录：

```text
NORMAL → OBSERVING
OBSERVING → SUSPECTED
SUSPECTED → NORMAL
SUSPECTED → CRITICAL
```

不要每 10 秒：

```text
memory = 100MB
```

写日志。

否则监控系统本身制造 IO。

---

# 125. Memory Alert 日志示例

```text
[WARNING] worker memory growth detected
app=xxx
pid=12345
worker_id=2
memory=312MB
baseline=180MB
growth=132MB
requests=120034
coroutines=23
state=suspected
```

日志只包含诊断数据，不包含：

```text
request body
user data
secret
```

---

# 126. 状态变化事件

建议以后可以：

```text
RuntimeMemoryStateChanged
```

但第一版不需要新增 Event 类型。

直接：

```text
Logger
```

即可。

如果当前 EventCtrl 已有合适扩展点，再接 Event，不新增新的 Event Bus。

---

# 127. Metrics 与 Logger 不互相依赖

不要：

```text
Metrics → Logger → Metrics
```

否则可能形成循环。

正确：

```text
RuntimeMetrics ───→ Snapshot
Logger ───────────→ Error / State Change
```

---

# 128. 生产环境默认不输出 Coroutine Backtrace

因为：

```text
Coroutine count = 10000
```

如果一次全部 backtrace：

```text
CPU ↑
Memory ↑
Response ↑
```

所以：

```text
backtrace = opt-in
```

---

# 129. Backtrace 安全接口

```text
GET /process-runtime?coroutine_id=123
```

只允许指定 CID。

不能：

```text
GET /process-runtime?all_backtrace=1
```

生产环境禁止一次输出所有 Coroutine Backtrace。当前 `/process-runtime` 尚未注册；本节是后续在满足第 47 节跨进程快照与访问控制前提后必须遵守的接口约束。

---

# 130. Runtime Diagnostics 权限

如果挂 HTTP：

必须进入现有 Worker control plane 权限体系。

不能直接开放：

```text
0.0.0.0/runtime
```

尤其 Diagnostics 包含：

```text
PID
Worker
Coroutine
Memory
Pool
```

属于内部运维数据。

---

# 131. 不新增认证系统

Swoolefy 已有 Auth / Guard / FrameworkContext。

本方案不新增：

```text
RuntimeAuth
AdminAuth
DiagnosticsAuth
```

直接复用现有 control plane / Auth 能力。当前 `CtlApi` 尚无框架内建的认证/授权检查，因此不能把其 Cron/Daemon + HTTP 入口判定误写为“已受认证保护”的 `/process-runtime`。

---

# 132. WorkerService 与 Runtime Diagnostics

当前 `HttpServer` 已经明确：

```text
Cron/Daemon + HTTP control plane
```

才走 `CtlApi`；否则 WorkerService 请求统一 503。citeturn9view0

所以 Runtime Diagnostics HTTP API 应遵守相同规则。

不能因为新增 Diagnostics 又绕过 WorkerService 的现有安全/协议判断。

---

# 133. 版本兼容

当前分支 README 明确 6.x 最低 PHP 8.4、Swoole 6.1+，推荐 Swoole 6.x。citeturn1view0

本方案按：

```text
PHP 8.4+
Swoole 6.2.x
```

设计。

不为 PHP 7.x 做兼容代码。

---

# 134. 不使用第三方 Metrics SDK

第一版不引入：

```text
Prometheus PHP Client
OpenTelemetry Metrics SDK
第三方 APM SDK
```

原因：

Swoolefy 已有：

```text
OpenTelemetry Trace
SysProcess
Logger
Server stats
Coroutine stats
```

新增第三方 Metrics Runtime 只会增加依赖和内存。

---

# 135. 最终运行模型

```text
                    WorkerStart
                        │
                        ▼
              RuntimeRegistry::init
                        │
        ┌───────────────┼────────────────┐
        │               │                │
        ▼               ▼                ▼
     Metrics          Memory         Diagnostics
        │               │                │
        │          Timer Sample           │
        │               │                │
        │               ▼                │
        │         MemoryHistory           │
        │               │                │
        │               ▼                │
        │       MemoryLeakDetector        │
        │               │                │
        └───────────────┼────────────────┘
                        ▼
                 Runtime Snapshot
                        │
          ┌─────────────┴─────────────┐
          ▼                           ▼
       CLI/API                  SysProcess
```

---

# 136. 最终 HTTP 请求链路

```text
HttpServer::request
        │
        ▼
      App::run
        │
        ├── metrics.requestStarted()
        │
        ├── parseHeaders
        ├── initCoreComponent
        ├── Application::setApp
        ├── defer
        ├── _init
        ├── _bootstrap
        ├── HttpRoute::dispatch
        │
        ▼
      Business
        │
        ▼
      finally
        │
        ├── metrics.requestError() [exception]
        ├── metrics.requestDuration()
        └── metrics.requestFinished()
                │
                ▼
             App::end
                │
                ├── ZFactory::removeInstance
                ├── pushComponentPools
                ├── clearComponent
                └── Application::removeApp
```

---

# 137. 最终 Worker 生命周期

```text
WorkerStart
    │
    ├── existing Swoolefy bootstrap
    ├── RuntimeRegistry init
    └── Memory timer start

        ↓

Worker Running
    │
    ├── Request metrics
    ├── Pool metrics
    ├── Coroutine stats
    ├── Memory sampling
    └── Diagnostics on demand

        ↓

WorkerStop
    │
    ├── clear Memory timer
    ├── optional final snapshot
    └── RuntimeRegistry reset

        ↓

WorkerExit
```

---

# 138. 第一阶段实施清单

## P0

### 1. RuntimeRegistry

```text
Worker-local lifecycle
```

### 2. RuntimeMetrics

```text
HTTP request
Worker request
5xx
Duration
Pool fetch/release
```

### 3. RuntimeDiagnostics

```text
Worker
Process
Server stats
Coroutine stats
Memory
Metrics
Pool
```

### 4. MemoryMonitor

```text
usage
real usage
peak
RSS
coroutine
request count
```

### 5. MemoryHistory

```text
bounded
warmup
rolling window
```

### 6. MemoryLeakDetector

```text
NORMAL
OBSERVING
SUSPECTED
CRITICAL
```

---

# 139. 第二阶段

完成第一阶段稳定后：

```text
SysProcess export adapter
Prometheus adapter
更详细 Pool diagnostics
Coroutine diagnostics
```

这些不是本次实现的前置条件。

---

# 140. 明确不做

本次不增加：

```text
自动 Worker 重启
自动 Worker Kill
自动 GC
Prometheus Server
Grafana
APM SaaS
Metrics DB
复杂 Event Bus
复杂 DI
复杂 RingBuffer
全量 Coroutine Backtrace
全量 Object Graph
```

这符合“不新增过度复杂设计”的原则。

---

# 141. 最终验收标准

## Runtime Metrics

必须：

- Worker-local
- 请求生命周期准确
- active 最终归零
- exception 不 double count
- Pool fetch/release 与实际生命周期一致
- 不产生动态高 Cardinality
- 不执行 IO
- 不影响 App::end()

## Worker Runtime Diagnostics

必须：

- 能读取 Worker PID/ID
- 能读取 Server stats
- 能读取 Coroutine stats
- 能读取 Memory trend
- 能读取 Metrics
- 能读取 Pool 状态
- 默认不读取 Backtrace
- 不暴露 Secret
- 单个 collector 失败不影响其他 collector
- CLI/API 都可以复用同一个 Snapshot

## Worker Memory Leak Detection

必须：

- WorkerStart 后启动
- WorkerStop 前停止
- History 有上限
- Warmup 后再检测
- 同时观察 PHP memory / real memory / RSS
- 关联 request count
- 关联 coroutine count
- 能识别持续增长
- 能从 SUSPECTED 恢复 NORMAL
- 不自动 restart
- 不自动 GC
- 不影响 Request path

---

# 142. 推荐最终代码提交拆分

建议不要一次提交所有内容。

### Commit 1

```text
feat(runtime): add worker runtime registry
```

### Commit 2

```text
feat(metrics): add worker runtime metrics
```

### Commit 3

```text
feat(metrics): instrument app request lifecycle
```

### Commit 4

```text
feat(metrics): instrument component pool lifecycle
```

### Commit 5

```text
feat(diagnostics): add worker runtime diagnostics
```

### Commit 6

```text
feat(memory): add worker memory monitor
```

### Commit 7

```text
feat(memory): add memory trend detection
```

### Commit 8

```text
 test(runtime): add runtime observability regression tests
```

这样出现问题时非常容易定位。

---

# 143. 最终架构判断

Swoolefy 6.2-x 最适合的 Runtime Observability 不是：

```text
重新设计一个 APM Framework
```

而是：

```text
把已经存在的 Runtime 状态统一起来
```

当前源码已经提供了非常好的基础：

```text
App
 ├── request lifecycle
 └── end cleanup

Application
 └── coroutine → App

ZFactory
 └── coroutine → singleton

Context
 └── coroutine context

ComponentTrait
 └── component / pool lifecycle

CoroutineManager
 └── coroutine stats/list/backtrace

BaseServer
 ├── worker lifecycle
 ├── server stats
 └── worker pid mapping

AbstractBaseWorker
 ├── worker identity
 ├── memory
 ├── coroutine count
 ├── reboot
 └── process status

SysProcess
 └── periodic system reporting

CtlApi
 └── process control/status

OpenTelemetry
 └── trace
```

citeturn7view0turn7view1turn8view0turn8view2turn10view0turn12view0turn12view1

所以最终设计应该是：

```text
                   Swoolefy Runtime
                         │
        ┌────────────────┼────────────────┐
        │                │                │
        ▼                ▼                ▼
       App            Worker           Pool
        │                │                │
        └────────────────┼────────────────┘
                         ▼
                 RuntimeRegistry
                         │
          ┌──────────────┼──────────────┐
          ▼              ▼              ▼
       Metrics      Diagnostics       Memory
          │              │              │
          │              │              ├── Snapshot
          │              │              ├── History
          │              │              └── Detector
          │              │
          │              ├── Process
          │              ├── Server
          │              ├── Coroutine
          │              ├── Pool
          │              └── Metrics
          │
          └── Request / Pool counters
```

这才是和 Swoolefy 6.2-x 当前代码结构真正匹配的生产级方案。

---

# 144. 最终结论

三个功能的价值分别是：

### ① Runtime Metrics

回答：

> **系统现在运行得怎么样？**

通过现有 App / Pool / Worker 生命周期提供低开销实时指标。

### ② Worker Runtime Diagnostics

回答：

> **线上出问题以后，这个 Worker 现在到底是什么状态？**

直接读取 Swoolefy 已有的 Process、Server、Coroutine、Pool、Memory 状态。

### ③ Worker Memory Leak Detection

回答：

> **这个常驻 Worker 是否正在持续恶化？**

通过：

```text
PHP Usage
Real Usage
RSS
Request Count
Coroutine Count
Rolling History
Trend
```

进行判断，而不是简单使用一个 `memory_get_usage()` 阈值。

最终原则：

> **Observability 是旁路能力，不应该成为 Swoolefy Runtime 的第二套生命周期。**
>
> **优先复用 `App → Component → Pool → Coroutine Context → Worker/Manager` 已有生命周期，只在准确的生命周期节点增加最小埋点。**

---

## 参考源码

- https://github.com/bingcool/swoolefy/tree/swoolefy-6.2-x
- `src/Core/App.php`
- `src/Core/Application.php`
- `src/Core/ZFactory.php`
- `src/Core/ComponentTrait.php`
- `src/Core/Coroutine/Context.php`
- `src/Core/Coroutine/CoroutineManager.php`
- `src/Core/BaseServer.php`
- `src/Http/HttpServer.php`
- `src/Worker/AbstractBaseWorker.php`
- `src/Core/SysCollector/SysProcess.php`
- `src/Worker/CtlApi.php`
