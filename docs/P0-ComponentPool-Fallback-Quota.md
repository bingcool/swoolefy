# P0 Component Pool Fallback 配额：连接风暴防护

> 对照当前仓库 `src/Core/ComponentTrait.php`、`src/Core/Coroutine/PoolsHandler.php`、`src/Core/Coroutine/CoroutinePools.php`（PHP 8.4）编写。  
> 目标：**不引入完整熔断器 / 半开窗口 / 自动探测下游健康**；只给「池耗尽后的降级新建」加上 Worker 级并发预算，并保证配额与对象生命周期绑定。  
> 原则一句话：连接池耗尽时可以降级，但降级连接必须纳入总并发连接预算，不能绕过 `max_pool_num` 的容量保护。

## 0. 定级（已确认）

| 项目 | 定级 | 判断 |
|------|------|------|
| Pool 耗尽后 `creatObject()` 旁路建连 | **P0** | `max_pool_num` 的保护被旁路 |
| fallback 无并发上限 | **P0** | 高并发可放大为连接风暴 |
| fallback 连接不进入 pool | 正常 | 非问题；禁止 push 回 channel |
| fallback 请求结束关闭 | 正常 | 正确；配额必须同时释放 |
| `popTimeout` + 两次 0.05s 短重试 | P1 | 本身不是根因；属于池内等待 |
| 完整 Circuit Breaker | **不做** | 当前阶段过度设计 |
| fallback soft quota | **P0 修复** | 最小且有效 |
| 超 quota 立即拒绝 | **P0 修复** | 建立 backpressure |

## 1. 现码事实（必须按这些路径改，不能只改 MySQL）

### 1.1 这是 Component Pool 的统一问题

`ComponentTrait` 被三处运行时共用：

| 类 | 场景 |
|----|------|
| `src/Core/Component.php` → `App` | HTTP 请求 |
| `src/Core/EventController.php` | EventApp / 协程内任务 |
| `src/Core/Swoole.php` | RPC / UDP / WebSocket |

`component_pools` 的 key 是 **组件别名**，不是驱动类型。现配置即可同时覆盖：

- `db` / PDO / PostgreSQL 封装
- `redis` / `predis` / `cache`
- 任何注册进 `components` 且出现在 `component_pools` 里的别名（Mongo 等同样走这条路）

因此本缺陷的优先级按 **框架连接池总容量保护** 处理，不是某一个 DB Component 的局部 bug。

### 1.2 现路径（连接风暴）

`PoolsHandler` 类注释已写明：取不到对象返回 `null`，上层可降级 `creatObject`。

```text
PoolsHandler::getObj()
  1. 空池 warm-up make(poolsNum)
  2. channel 有空闲 → pop
  3. objectCount < poolsNum → 懒创建 1 个
  4. 全借出 → channel->pop(popTimeout)   // 默认 1s
  5. 再 pop(0.05) 两次                   // 竞态短重试
  → 仍失败 return null

ComponentTrait::get($name)                    // ~L246–275
  cid >= 0 且 $name ∈ component_pools
      → CoroutinePools::getPool($name)->fetchObj()
      → 对象：记入 componentPoolsObjIds，return（池化命中）
      → null：poolFetchError 指标，然后 fallthrough
  return creatObject($name, $components[$name])   // 无上限
```

`fetchObj()` 只有真正拿到对象才 `callCount++`；超时返回 `null` 不虚增账本（`PoolsHandlerConcurrencyTest::testTimedOutWaiterDoesNotConsumeCapacityOrLease` 已钉死）。  
**容量账本在池内是对的；风暴发生在池外。**

### 1.3 降级对象为何不进池（保持）

`componentPoolsObjIds` 只在 `fetchObj()` 成功时写入。fallback 的 `creatObject()` 结果：

- 不进 `componentPoolsObjIds`
- `pushComponentPools()` 不会 `pushObj` 回 channel（避免污染池）
- `App::end()` / `EventController::end()` / `Swoole::end()` 随后 `clearComponent(null, true)`，DTO destruct，底层连接关闭

这一点 **正确，P0 不改**。要改的是：关闭连接时必须把 fallback 配额减回去。

### 1.4 现码已有的「无 yield 预占」协议，配额应复用同一模型

`PoolsHandler::objectCount` 的教训已经写在类注释里：创建回调可能 yield，必须先在无 yield 连续代码里预占，再执行回调。

fallback 配额必须同样：

```text
检查 inflight < max  +  inflight++     // 无 yield
再 creatObject()                       // 允许 yield（TCP 握手）
失败 → inflight--
```

禁止：`creatObject()` 成功后再 `++`（握手期间会超卖）。

### 1.5 配额必须是 Worker 进程级，不是单次 Request 级

`CoroutinePools` 是 Worker 单例；并发 HTTP 请求各有一份 `App` 容器，但抢的是 **同一个** `PoolsHandler`。

下游变慢时：20 个池连接全部借出（被慢查询占用）→ 后续每个请求各自 `creatObject()`。  
若配额记在 `App` 实例上，每个请求仍可建 1 条，等于没有总预算。

**账本放在 `PoolsHandler`（按 poolName），与 `objectCount` 同级。**

### 1.6 现码里「等待」发生的位置

| 阶段 | 是否等待 | P0 |
|------|----------|-----|
| `getObj()` 第 4 步 `pop(popTimeout)` | 是，默认 1s | 保留（等归还，合理） |
| `getObj()` 第 5 步两次 `pop(0.05)` | 是，最多 0.1s | 保留为 P1；属池内竞态 |
| `get()` 拿到 `null` 之后 | **现码不再等，直接 creatObject** | 配额判断必须也是立即的 |
| 配额用尽后再 `pop` / `sleep` / 重试 `fetchObj` | 现码没有；**禁止新增** | P0 硬约束 |

正确 backpressure：

```text
DB 慢
 → Pool 全借出
 → 等 popTimeout（仍可能等到归还）
 → 仍 null
 → 检查 fallback quota（立即）
      ├── 有额度 → creatObject()，inflight++
      └── 无额度 → 立即抛 503，不再 wait
 → 应用拒绝
 → DB 不再被新握手打满
```

禁止：

```text
Pool exhausted → wait 1s → retry → fallback quota exhausted → 再 wait
```

## 2. 明确不做

- 不引入 Circuit Breaker（关闭 / 半开 / 探测探针 / 错误率窗口）。
- 不把 fallback 连接 `pushObj` 回池。
- 不修改 `objectCount` / `callCount` 语义去「把 fallback 算进 poolsNum」（那会破坏池账本；fallback 是池外预算）。
- 不在 `getObj()` 返回 null 后再调用一次完整 `fetchObj()`。
- 不按请求 URL / 用户 ID 建指标键。
- 不把 `makeNewObject()`、非协程 `cid < 0` 绕过池、`ClusterRedisClient` 自管连接纳入本次 P0（见 §9）。
- 不把「无限 fallback」做成一等配置项。

## 3. 目标语义

**每个 Worker、每个组件别名：**

```text
真实并发连接上限 ≈ max_pool_num + fallback.max_concurrent
```

推荐默认（未写 `max_concurrent` 时，比无限安全一个数量级）：

```text
!isset(fallback['max_concurrent'])
    → fallback 额度 = 2 × max_pool_num
    → 总上限 = max_pool_num + 2 × max_pool_num = 3 × max_pool_num

例：max_pool_num=20 且未配置 max_concurrent
    → 池 20 + 降级最多 40 → 真实连接 ≈ 60
```

解析必须用 PHP `isset()`，不能用 `array_key_exists()`：`DefaultConfig` 里占位 `null` 时 `isset` 为 false，才会走到 2x 额度。显式写出 `'max_concurrent' => 5` 才覆盖缺省。

生产更紧的配额（用户推荐形态）：

```php
'component_pools' => [
    'db' => [
        'max_pool_num' => 20,
        'max_push_timeout' => 2,
        'max_pop_timeout' => 1,
        'max_life_timeout' => 10,
        'fallback' => [
            'enabled' => true,
            'max_concurrent' => 5,   // 高峰：20 pool + 最多 5 条降级
        ],
    ],
    'redis' => [
        'max_pool_num' => 20,
        'fallback' => [
            'enabled' => true,
            'max_concurrent' => 5,
        ],
    ],
],
```

`fallback.enabled = false`：池等待失败后 **禁止** `creatObject`，立即拒绝。总连接 = `max_pool_num`。

```text
正常：     20 pool
异常高峰： 20 pool + ≤ max_concurrent fallback
超过：     拒绝（HTTP 503 / 非 HTTP 抛同一异常）
```

## 4. 核心设计

### 4.1 账本：`PoolsHandler` 增加 fallback inflight

与现有 `objectCount` 并列，**不要混进 `objectCount`**：

```text
poolsNum          池容量（channel + 借出 + 正在创建）
objectCount       池内资源总账
callCount         已借出未归还
fallbackMax       降级并发上限（未配置时 = 2 × poolsNum；总连接 3x）
fallbackEnabled   是否允许降级
fallbackInflight  当前仍存活的降级连接数；0 <= inflight <= fallbackMax
```

API（均无 yield）：

```php
public function reserveFallback(): bool;   // enabled 且 inflight < max → ++inflight，true
public function releaseFallback(): void;   // inflight > 0 → --
public function getFallbackInflight(): int;
public function getFallbackMax(): int;
```

`reserveFallback()` 在 `enabled=false` 或 `inflight >= max` 时立刻 `false`，**内部不得 pop / sleep**。

配置解析放在现有 `CoroutinePools::addPool()`（已 merge `DefaultConfig` 并 `setPoolsNum` / `setPopTimeout`）。新增：

```php
const DefaultConfig = [
    'max_pool_num' => 30,
    'max_push_timeout' => 2,
    'max_pop_timeout' => 1,
    'max_life_timeout' => 10,
    // 新增：缺省开启降级；!isset(max_concurrent) 时额度 = 2 * max_pool_num（总 3x）
    'fallback' => [
        'enabled' => true,
        'max_concurrent' => null, // null / 未设置 → 2 * max_pool_num
    ],
];
```

解析：

```php
$fallback = is_array($poolConfig['fallback'] ?? null) ? $poolConfig['fallback'] : [];
$enabled = (bool) ($fallback['enabled'] ?? true);
if (!isset($fallback['max_concurrent'])) {
    $fallbackMax = 2 * $poolsNum;          // 总 3x
} else {
    $fallbackMax = (int) $fallback['max_concurrent'];
}
```

校验：显式 `max_concurrent` 必须是 `>= 0` 的 int；`0` 且 `enabled=true` 等价于不允许降级（与 `enabled=false` 同效）。禁止负数。

### 4.2 `ComponentTrait::get()`：null 之后的唯一降级入口

现码 `fetchObj()` 成功才写入 `componentPoolsObjIds`。P0 在 **null 分支** 插入配额，不要改成功路径。

```text
fetchObj()
 ├── 对象
 │     poolFetched 指标
 │     记入 componentPoolsObjIds
 │     return
 └── null（或 poolHandler 不存在，见下）
       poolFetchError 指标（已有，保留）
       reserveFallback() 立即
         ├── false → throw ComponentPoolExhaustedException(503)
         └── true
               try
                 creatObject()
                 给 DTO 绑上幂等 FallbackLease
                 return          // 不进 componentPoolsObjIds
               catch
                 releaseFallback()
                 throw
```

`poolHandler` 缺失时，现码也会 `creatObject`。P0：若别名在 `component_pools` 里但 `getPool()` 为空，视为配置错误，**不要**无上限建连；抛 `SystemException`（启动期 `EventCtrl::registerComponentPools` 本应已经 `addPool`）。这不是风暴主路径，但要堵住。

`fetchObj()` **抛异常**（现码 `catch` 后 `poolFetchError` 再 rethrow）：**不要**再走 fallback。构造失败与「池忙」不同；沿用现语义。

### 4.3 配额必须绑在对象生命周期上

用户示意的 `try { create } finally { release; close }` **不能写在 `get()` 里**：`get()` 返回后业务还要用这条连接。

正确绑定：

```text
reserve  成功  ──►  DTO 持有 FallbackLease
creatObject 失败 ──►  立即 release（对象未交给业务）
业务异常 / 正常结束
  App::end / EventController::end / Swoole::end
      pushComponentPools()     // 只归还池化对象；fallback 不在 objIds 里
      clearComponent(null,true)
          unset DTO
          FallbackLease::release() 幂等 --inflight
          DTO::__destruct 关掉底层连接
```

**Lease 必须幂等**：`release()` 第一次 `--inflight`，之后 no-op。防止 `clearComponent` + destructor 双减把账本打穿。

推荐最小实现（不必新建很多类，但必须有「已释放」标志）：

```php
final class PoolFallbackLease
{
    private bool $released = false;

    public function __construct(private readonly PoolsHandler $pool) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->pool->releaseFallback();
    }

    public function __destruct()
    {
        $this->release(); // 兜底：业务异常未走到 end() 时仍能归还额度
    }
}
```

挂载位置二选一（实现时只选一种，禁止两套账）：

1. **（推荐）** `ContainerObjectDto` 增加内部属性 `__fallbackLease`（加入现有 `__attributes` 白名单），`clearComponent` / `__unset` unset DTO 后由 destructor 释放。  
2. `ComponentTrait` 另存 `$componentFallbackLeases[$name]`，在 `clearComponent` / `__unset` 显式 `release()`。

无论哪种，**禁止只在业务 `try/finally` 里减**——框架 `get('db')` 的调用方不会记得减。

同一请求、同一 cid 第二次 `get('db')` 命中 `$this->containers[$name]` 协程单例，**不得再次 reserve**。

### 4.4 释放点清单（漏一个就会永久占满 inflight）

| 路径 | 现行为 | P0 |
|------|--------|----|
| `App::end()` → `pushComponentPools` → `clearComponent(all)` | fallback 被 unset | lease 必须 release |
| `EventController::end()` / `Swoole::end()` | 同上 | 同上，不要只改 HTTP |
| `clearComponent($name)` 中途扔掉组件 | unset 容器 | 若该 name 是 fallback，release |
| `__unset($name)` | 池化对象会 `pushObj`；fallback 不在 objIds | 补 release |
| `creatObject` 握手失败 | 现无配额 | `reserve` 后 `catch` 里 release |
| Worker 进程崩溃 | 进程消失 | inflight 自然清零，无需持久化 |
| Lease `__destruct` | 无 | 幂等兜底，防止 end 被跳过 |

`Coroutine::defer` 已保证 HTTP `App::end()` 在协程退出时执行（`App::defer()`）。EventController / Swoole 同样有 `end()`。destructor 是防遗漏，不是主协议。

### 4.5 立即拒绝的异常与 HTTP 503

对齐现有 `RateLimitExceededException`（code=429，`SwoolefyException::response()` 在 `400 <= code < 600` 时 `withStatus`）。

`getMessage()` 就是客户端 JSON 的 `msg`（生产环境 `SwoolefyException::response()` 也只回这段，不带 file/line）。**禁止只回一句英文 exhausted**，必须让调用方一眼看出是池打满：

```text
连接池[db]已达到最大上限60（池容量20 + 降级额度40，当前降级占用40）
```

```php
namespace Swoolefy\Exception;

final class ComponentPoolExhaustedException extends SystemException
{
    public function __construct(
        string $poolName,
        int $maxPoolNum,
        int $fallbackMax,
        int $fallbackInflight,
        int $code = \Swoole\Http\Status::SERVICE_UNAVAILABLE, // 503
        ?\Throwable $previous = null,
    ) {
        $totalCap = $maxPoolNum + $fallbackMax;
        parent::__construct(
            sprintf(
                '连接池[%s]已达到最大上限%d（池容量%d + 降级额度%d，当前降级占用%d）',
                $poolName,
                $totalCap,
                $maxPoolNum,
                $fallbackMax,
                $fallbackInflight,
            ),
            $code,
            $previous,
        );
        $this->setContextData([
            'pool' => $poolName,
            'max_pool_num' => $maxPoolNum,
            'fallback_max' => $fallbackMax,
            'fallback_inflight' => $fallbackInflight,
            'total_cap' => $totalCap,
        ]);
    }
}
```

HTTP 响应形态（与现有 `returnJson($contextData, $code, $errorMsg)` 对齐）：

```json
{
  "code": 503,
  "msg": "连接池[db]已达到最大上限60（池容量20 + 降级额度40，当前降级占用40）",
  "data": {
    "pool": "db",
    "max_pool_num": 20,
    "fallback_max": 40,
    "fallback_inflight": 40,
    "total_cap": 60
  }
}
```

- HTTP：状态码 503 + 上面 JSON；`msg` 必须含组件别名和数字上限。  
- RPC / WS / UDP：同一异常向上抛，由各协议现有异常处理消化；**不要**在 Trait 里直接写 HTTP。  
- `msg` / `data` 只含别名与计数，**不要**打 DSN / SQL / 密码。

### 4.6 指标（低基数，可选但建议一起做）

`RuntimeMetrics` 已有按 `component_pools` 白名单归因的 `fetch_total` / `release_total` / `fetch_error_total`。P0 可加 **固定字段**（仍按启动时别名，禁止动态 key）：

| 字段 | 含义 |
|------|------|
| `fallback_total` | 成功 `creatObject` 降级次数 |
| `fallback_reject_total` | 配额拒绝次数 |
| `fallback_inflight` | 可选 gauge；或只在 `PoolsHandler` 上暴露给诊断 |

`fetch_error_total` 在 null 时已经 +1，保留（表示池没借到）。reject 是下一步，不要把 reject 再记成一次 fetch_error 导致双计语义不清。

诊断快照 `worker.pool.aliases.<alias>` 可增加 `fallback_inflight`（来自 `PoolsHandler::getFallbackInflight()`，与计数器互补）。仍禁止高基数。

## 5. 修改范围

| 优先级 | 文件 | 修改 |
|--------|------|------|
| P0 | `Engine` 无 | 与 Workflow 无关 |
| P0 | `src/Core/Coroutine/PoolsHandler.php` | `fallbackInflight/Max/Enabled` + `reserveFallback` / `releaseFallback` |
| P0 | `src/Core/Coroutine/CoroutinePools.php` | `DefaultConfig['fallback']`；`addPool` 写入 Handler |
| P0 | `src/Core/ComponentTrait.php` | `get()` null 分支配额；`clearComponent` / `__unset` 释放 lease |
| P0 | `src/Core/Dto/ContainerObjectDto.php` | 若选 DTO 挂载：`__fallbackLease` 加入 attributes |
| P0 | `src/Exception/ComponentPoolExhaustedException.php` | 新建，code=503 |
| P0 | `src/Stubs/app.conf.stub.php` + `EventCtrl::registerComponentPools` 注释 | 示例配置 |
| P0 | `PHPUintTest/Coroutine/Core/PoolsHandlerConcurrencyTest.php` 或新 `ComponentPoolFallbackTest` | 配额、立即拒绝、lease 释放 |
| P1 | `RuntimeMetrics` / `docs/RuntimeObservability.md` | fallback 计数（无高基数） |
| 不做 | `PoolsHandler::getObj()` 等待策略 | 不在本 P0 改 popTimeout |

`Test/Config/app.php` 的 `db.max_pool_num=1` 在缺省 fallback 额度 `2 × 1` 后，总上限变为 3 条。实现时跑现有 Coroutine / HTTP / Module 测，不够的用例改为显式 `'fallback' => ['max_concurrent' => N]`。

## 6. `get()` 伪代码（唯一允许降级的位置）

```php
if (in_array($name, $this->componentPools, true) && $cid >= 0) {
    $pool = CoroutinePools::getInstance()->getPool($name);
    if (!$pool instanceof PoolsHandler) {
        throw new SystemException("component_pools [{$name}] has no PoolsHandler");
    }

    try {
        $this->containers[$name] = $pool->fetchObj();
    } catch (\Throwable $e) {
        RuntimeRegistry::metrics()?->poolFetchError($name);
        throw $e; // 不 fallback
    }

    if (is_object($this->containers[$name])) {
        RuntimeRegistry::metrics()?->poolFetched($name);
        $this->componentPoolsObjIds[] = spl_object_id($this->containers[$name]);
        return $this->containers[$name];
    }

    RuntimeRegistry::metrics()?->poolFetchError($name);

    if (!$pool->reserveFallback()) {
        RuntimeRegistry::metrics()?->poolFallbackRejected($name); // 若做指标
        throw new ComponentPoolExhaustedException(
            $name,
            $pool->getPoolsNum(),
            $pool->getFallbackMax(),
            $pool->getFallbackInflight(),
        );
    }

    try {
        $dto = $this->creatObject($name, $components[$name]);
        $dto->__fallbackLease = new PoolFallbackLease($pool);
        RuntimeRegistry::metrics()?->poolFallbackCreated($name);
        return $dto;
    } catch (\Throwable $e) {
        $pool->releaseFallback();
        throw $e;
    }
}

return $this->creatObject($name, $components[$name]); // 未启用池的组件，保持原样
```

注意：`creatObject` 在 `containers[$name]` 已是 `null` 时，现实现因 `!isset || !is_object` 会重建。保持这一行为。

## 7. 行为对照

| 场景 | 现码 | P0 |
|------|------|----|
| 池有空闲 | 借出 | 不变 |
| 池未满 | 懒创建 | 不变 |
| 全借出，popTimeout 内归还 | 借到 | 不变 |
| 全借出，等待后仍 null，fallback 有额度 | `creatObject` 无限 | `creatObject`，inflight++，上限内 |
| 全借出，fallback 额度用尽 | 继续 `creatObject`（风暴） | **立即 503**，不再 wait |
| fallback 请求结束 | 关闭连接，无账本 | 关闭连接 + inflight-- |
| fallback 业务异常 | 关闭连接（end/GC），无账本 | 必须 inflight--（lease 幂等） |
| `enabled=false` | 仍 creatObject | 立即 503 |
| 未配置 `component_pools` 的组件 | 一直 creatObject | 不变（本来就不是池） |

## 8. 测试

现成相关：`PHPUintTest/Coroutine/Core/PoolsHandlerConcurrencyTest.php`（只测 Handler，**刻意不走 Trait**，所以补不到风暴）。必须新增走 `ComponentTrait::get()` 的协程测。

1. **上限**：`max_pool_num=2`，`fallback.max_concurrent=1`，3 个协程长期占用 → 第 4 个 `get()` 抛 `ComponentPoolExhaustedException`，且从 `fetchObj` 返回 null 到 throw 的耗时 **远小于 popTimeout**（证明没有二次等待）。  
2. **3x 缺省**：只配 `max_pool_num=2`，不写 `fallback.max_concurrent` → 允许 4 条降级（`2 × 2`），总 6；第 7 个拒绝。显式 `'max_concurrent' => 0` 不得走缺省 2x。  
3. **lease 释放**：3 个占用结束后，第 4 个能再次 fallback；`getFallbackInflight()===0`。  
4. **creatObject 失败回滚**：构造抛错后 `inflight===0`，后续请求仍能降级。  
5. **业务异常释放**：`get()` 成功后业务 throw，`end()`/`clearComponent` 后 inflight 归零。  
6. **不入池**：fallback DTO 的 `spl_object_id` 不在 `componentPoolsObjIds`；`pushObj` 不被调用。  
7. **双组件隔离**：`db` 配额用尽不影响 `redis` 的 fallback。  
8. **enabled=false**：池耗尽直接 503，零次额外构造。  
9. **协程单例**：同一 cid 两次 `get('db')` 只 reserve 一次。  
10. 回归：现有 `PoolsHandlerConcurrencyTest` 仍全绿（池内账本不变）。

## 9. 明确排除（避免范围膨胀）

| 路径 | 说明 |
|------|------|
| `ComponentTrait::makeNewObject()` | 显式「每次新建」API，本来就不走池 |
| `cid < 0`（非协程） | `get()` 直接 `creatObject`，不进 `getPool`；CLI/同步脚本问题，另案 |
| `ClusterRedisClient` 自管 `creatObject` | 独立连接路径，不走 `component_pools` fetch |
| `getObj()` 第 5 步 `make(1)` + `pop(0.05)` | 池已满时 `reserveCreateSlot` 会立刻失败，只多等最多 0.1s；P1 |

## 10. 实施顺序

```text
1. ComponentPoolExhaustedException（503）
2. PoolsHandler fallback 账本 + reserve/release（单测可直接打 Handler）
3. CoroutinePools::addPool 解析 fallback：`!isset(max_concurrent)` → `2 * max_pool_num`（总 3x）
4. PoolFallbackLease 幂等释放 + destructor 兜底
5. ComponentTrait::get() null 分支；clearComponent / __unset 释放
6. stub / EventCtrl 注释示例
7. 协程单测（上限、立即拒绝、lease、双组件）
8. 可选指标（无高基数）
```

## 11. 验收标准

- [ ] 任意 `component_pools` 别名（db / redis / predis / cache / pg …）在池耗尽后，降级次数 ≤ `fallback.max_concurrent`。  
- [ ] 超过配额：**立即**失败，不再 `pop` / `sleep` / 二次 `fetchObj`。  
- [ ] HTTP 映射 503；`msg` 为中文「连接池[别名]已达到最大上限N（池容量… + 降级额度…）」，`data` 带计数；不含 DSN / 密码。  
- [ ] fallback 仍不进 `componentPoolsObjIds`，不 `pushObj`。  
- [ ] `creatObject` 失败、业务异常、`end()`、`__unset`、`clearComponent` 都不会让 `fallbackInflight` 只增不减。  
- [ ] `release()` 幂等，不会减到负数。  
- [ ] 默认未设置 `fallback.max_concurrent` 时，降级额度 = `2 × max_pool_num`，总上限 `3 × max_pool_num`，不再是无限。显式配置优先于缺省。  
- [ ] 不引入 Circuit Breaker。  
- [ ] PHP 8.4 语法检查通过；Handler 并发旧测 + 新 fallback 测通过。

## 12. 最终语义

```text
                max_pool_num 条池连接（objectCount 账本）
                         │
            ┌────────────┴────────────┐
            │                         │
      fetchObj 命中              fetchObj 超时 null
            │                         │
         归还入池              reserveFallback() 立即
                                      │
                         ┌────────────┴────────────┐
                         │                         │
                   inflight < max              无额度 / disabled
                         │                         │
                   creatObject()              立即 503
                   inflight++                 不再等待
                         │
              请求结束 unset DTO
              lease.release() 幂等
              连接关闭（不入池）
```

要消除的错误链路：

```text
慢查询占满池 → getObj wait → null → creatObject() × N（无上限）
 → Worker 与 DB 连接一起被打满
```

本方案只给已存在的降级路径加上总预算和立即拒绝，不扩大连接池功能，不引入分布式协调。
