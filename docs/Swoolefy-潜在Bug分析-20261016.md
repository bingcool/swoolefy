# Swoolefy 框架潜在 Bug 分析

> 分支：`swoolefy-6.2-x`，分析日期：2026-10-16。
> 基于对当前源码的逐文件核对（组件池、协程调度、启动链、路由、Worker、Sidecar 等）。
> 说明：`docs/Optimize20260728.md`、`docs/Swoolefy-P1-Bug-Fix-Technical-Plan.md` 为历史问题清单，
> PoolsHandler / Sidecar 等代码已明显做过加固（对象账本、lease 幂等等）。
> 以下列出的是**当前代码上仍能站得住脚的问题或风险点**，并按可信度分档。
> 已修复其中 5 组：#1/#2/#3（组件池账本）、#4/#5（Hook 去重与迟到注册）、#7（整机 OPcache 踩踏）、#8（定时器内存表）、#9（版本门槛统一）；其余条目（#6、#10 及二、三档）暂不处理，保留备查。

---

## 一、代码层面可确认的真实缺陷

### 1. 池对象过期检查只做一轮，可能把过期连接交给业务

- 位置：`src/Core/Coroutine/PoolsHandler.php` `pop()`（约 L661-L677）
- 现象：pop 到过期对象后 `discardObject + make(1)`，然后**再次 pop 时不再检查 `__objExpireTime`**。
  若 channel 头部还排着其他过期对象（池里多个连接同时到期很常见，`lifeTime` 相同、
  随机抖动只有 1–10 秒且只在创建时加），第二次 pop 会直接把又一个过期对象返回。
- 影响：对 DB/Redis 就是把已断的连接交给业务，表现为间歇性 "gone away" 类错误。
- 修复建议：把过期检查改为 `while` 循环，直到拿到未过期对象或 channel 为空。

### 2. 跨协程误用池对象是"静默泄漏"，没有任何告警

- 位置：`src/Core/Coroutine/PoolsHandler.php` `pushObj()`（约 L352-L371）
- 现象：对 `__coroutineId !== getCid()` 的归还**直接 return，不记日志不计数**。
  业务把借出的 db/redis 传进 `goApp` 子协程或存静态属性（框架明令禁止但没有运行时拦截），
  真实借用协程结束时归还失败 → `callCount` 只增不减 → 池被"幽灵借出"吃满，
  最后全部流量走 fallback 直到 `ComponentPoolExhaustedException`。
  且 `fallbackInflight` 依赖 lease 释放，对象若被业务持有到 Worker 寿命结束，额度同样悬空。
- 修复建议：至少在拒绝归还路径上打 warning + `RuntimeMetrics` 计数，把误用暴露出来。

### 3. `fetchObj()` 成功计数后存在半泄漏路径

- 位置：`src/Core/Coroutine/PoolsHandler.php` `fetchObj()`（约 L431-L461）
- 现象：`callCount++` 之后若 `getObject()` / `enableDynamicDebug()` / 上层抛异常
  （异常分支只记日志再 rethrow），这个 DTO 从未进入 `componentPoolsObjIds`
  （记 id 发生在 `ComponentTrait::getPooledOrFallback()` 中 fetchObj 正常返回之后），
  请求结束不会有人归还它 → `callCount` 和 `objectCount` 双双悬空。
- 影响：低概率，但每发生一次就永久损失一个池槽位。

### 4. `Hook` 用 `md5($callable_name)` 去重会误杀合法 hook

- 位置：`src/Core/Hook.php` `addHook()`（约 L37-L59）
- 现象：`is_callable($func, true, $callable_name)` 对 `[new A(), 'handle']`、`[new B(), 'handle']`
  产生的 name 完全相同 → 第二个 hook **被静默丢弃**。闭包同理：同一行代码注册两次
  （循环里常见）只生效一次。
- 修复建议：改用 `spl_object_id($callable) + 方法名` 做 key。

### 5. `callHook` 后的迟到注册造成静态数组残留

- 位置：`src/Core/Hook.php` `callHook()`（约 L85-L87）
- 现象：触发后 `unset(self::$hooks[$cid])`；若后置中间件 / `_afterAction` 再调
  `afterRequest()` 注册，新条目永远不会被调用也不会被清理。
- 影响：常驻 Worker 里 `Hook::$hooks` 按 cid 单调增长（需业务先误用才触发）。
  与第 4 条同源，属于同一块薄弱代码。

### 6. `registerComponentPools` 对配置残缺是 crash-loop 而不是可读报错

- 位置：`src/Core/EventCtrl.php` `registerComponentPools()`（约 L412-L430）
- 现象：`$appConf['components']` 未做存在性检查。`app.php` 里只配 `component_pools`
  没配 `components` 时，Worker 启动触发 undefined key warning——框架把 warning 转异常，
  结果是**每个 Worker 启动即崩、Swoole 无限重启**，CLI 前台只看到重复堆栈。
- 修复建议：启动期校验并给出可读错误（fail fast + 明确配置项名）。

### 7. 每个 Worker 启动都 `opcache_reset()` 的全机缓存踩踏

- 位置：`src/Core/BaseServer.php` `workerStartInit()` → `clearCache()`（L176-L219、L580-L588）
- 现象：`apc_clear_cache + opcache_reset` 在**每次任意 Worker 启动**时执行。
  OPcache 是整台机器共享的：worker_num=8 + `max_request` 滚动回收时，
  会把所有 Worker（乃至同机其他 swoolefy 应用）的字节码缓存反复清空，命中率长期被打穿。
- 定性：性能 bug，生产影响大。建议仅在 Master 启动或显式 reload 时清理，
  或改用 `opcache_invalidate` 精准失效。

### 8. 定时器内存表写满即静默失败

- 位置：`src/Core/BaseServer.php` `$_table_tasks`（L101-L125）、`src/Core/Timer/Tick.php`（L155/L186/L267/L292）
- 现象：`table_ticker` / `table_after` 各 size=4、单字段 8096B，`Tick.php` 把
  `json_encode($_tick_tasks)` **整体写入固定行键**。定时器多了 JSON 超 8096B 时
  `TableManager::set` 返回 false（Swoole Table 行为），没有任何检查——跨进程定时器视图从此不一致。
  行键不区分进程/Worker，多写者还会互相覆盖。

### 9. 版本门槛互相矛盾

- 位置：`src/Cmd/BaseCmd.php` `initCheck()`（要求 PHP ≥ 8.1，L189-L203）
  vs `src/Core/BaseServer.php` `checkVersion()`（要求 PHP ≥ 8.4，L226-L231）
- 现象：8.2/8.3 环境能过 CLI 检查、启动后才抛异常，报错时机差了一整段启动流程。

### 10. 样例入口的端口冲突设计

- 位置：`daemon.php` 与 `cron.php`（`APP_META_ARR` 中 `Test` 均为 `worker_port=9506`）
- 现象：两种 Worker 服务本身都是监听该端口的 HTTP 服务（CtlApi 控制面），
  同一应用同时跑 daemon + cron 必然 `EADDRINUSE`，入口注释没有说明这一点。

---

## 二、容易被触发的设计性陷阱（半 bug 性质）

### 11. finish 回调对业务裸调 `$server->finish()` 不兼容

- 位置：五个协议的 `on('finish')`，如 `src/Http/HttpServer.php`（L264-L278），
  共 5 处 `list($data, $contextData) = unserialize($params)`。
- 现象：格式契约仅由 `AsyncTask` 内部保证。业务在 task worker 里直接 `$server->finish('x')`
  会得到解构 warning→异常，错误信息对使用者毫无提示性。

### 12. `getOneFreePort` 选端口与实际 bind 之间的竞态

- 位置：`src/Core/Func/function.php`（L20-L52）、`script.php`
- 现象：socket 关闭后端口可能被其他进程抢占再 bind 失败；两个 script 并发启动时
  `exclude` 列表只排除硬编码端口，不排彼此。

### 13. `parseHeaders` 键名大写化改写了原始 request 的读取约定

- 位置：`src/Core/App.php` `parseHeaders()`（L187-L199）
- 现象：把 server 键全部转大写并注入 `HTTP_*`。在此之后读 `$request->server['path_info']`
  （Swoole 原始小写风格）会得到 null——框架内部已统一读大写，但业务中间件/Controller
  直接用 `$this->swooleRequest` 时很容易踩；另外 header `x-a-b` 与 `x-a_b` 归一后同名互相覆盖。

### 14. `ComponentTrait::get()` 的 cid 失配分支存在跨协程清理风险

- 位置：`src/Core/ComponentTrait.php` `get()`（L240 起）
- 现象：`containers[$name]` 对象 cid 与当前协程不符时走 `clearComponent($name)`——
  如果那个 DTO 其实是**另一个协程正在借用的对象**（业务跨协程传了 App/组件引用），
  clear 会触碰仍在使用中的连接。与第 2 条同源：框架对"禁止跨协程持有"只有文档约束，
  没有运行时防护，误用后果是账本紊乱而非快速失败。

### 15. 路由/参数元数据缓存的陈旧引用

- 位置：`src/Http/HttpRoute.php` `$routeCache` / `$actionParamMetaCache`（L123-L137）
- 现象：缓存里持有 `RouteOption` 对象引用（限流参数等）。Nacos 热更配置、reload 后
  未重启 worker 时，是否即时生效取决于是否命中缓存——行为不一致且无失效机制
  （缓存 key 只有 uri+method）。热重载场景值得核对。

### 16. 异常 message 拼接用户可控 URI

- 位置：`src/Http/HttpRoute.php` `bindActionParams()` 等处
  `"... ||| " . $requestUri` 进入异常消息 → 日志与（非 prd 环境）响应。
- 影响：日志注入（伪造 `[request end]` 风格行）和反射型内容风险，虽然面很小。

---

## 三、依赖外部条件、需要动态验证的疑点

### 17. Worker 服务 `while(true)` 忙轮询窗口

- 位置：`src/Worker/AbstractWorkerProcess.php` `run()`（L103-L111）
- 现象：`!isDue()` 分支只在 `waitToExit` 为真时退出，否则 `continue` **不 sleep**——
  若 reboot 流程中 `isDue()` 已 false 而 `waitToExit` 因时序未置位，该进程 CPU 空转。
  需要在重启压测中观察 CPU 曲线确认。

### 18. `Timer::tick` 的漂移与最小粒度强制

- 位置：`src/Core/Coroutine/Timer.php`（L29-L71）
- 现象：基于 `Channel->pop($sec)` 的 tick 每轮耗时都会累加漂移（任务执行时间不扣除），
  且小于 10ms 被抬到 10ms 无任何提示；与 `\Swoole\Timer` 语义不同但同名，
  业务容易按 Swoole 语义理解。

### 19. Sidecar 启动时序竞态已有文档、但没有内置重试

- 位置：`src/Core/Sidecar/README.md`
- 现象：README 明确"第一轮 `connect failed: rocketmq` 属正常"，但消费进程 `run()` 开头的
  `health()` 失败**不会自动复查**，业务照 README 模板在启动期做健康检查就会误报。
  属于"文档已知、代码未兜底"。

---

## 四、建议排查优先级

| 优先级 | 条目 | 理由 |
|---|---|---|
| P0 | #1 #2 #3（组件池账本） | 直接影响连接池正确性与容量，静默难排查 |
| P0 | #6 #8 | 配置错误变 crash-loop / 定时器状态不一致 |
| P1 | #4 #5（Hook） | 语义性 bug，影响 afterRequest（含 Session 保存） |
| P1 | #7 #9 #10 | 性能踩踏与启动体验 |
| P2 | #11–#16 | 误用防护与热更一致性 |
| 待验证 | #17–#19 | 需压测/动态场景确认 |

## 五、修复切入点建议

1. **#1 + #2 先行**：`PoolsHandler::pop()` 过期检查循环化；`pushObj()` 拒绝归还路径加
   warning 日志与 `RuntimeMetrics` 计数。两处改动小、风险低、收益直接。
2. `ComponentTrait::get()` 的 cid 失配分支改为只清理本协程引用，不动他人借出的 DTO。
3. `Hook` 的 key 策略换成 `spl_object_id`，并给 `addHook` 增加"callHook 后禁止注册"的告警。
4. `registerComponentPools` / `checkVersion` / `initCheck` 统一为启动期 fail-fast + 可读错误。
5. `clearCache()` 从 workerStartInit 摘除或改为仅 Master/显式 reload 时执行。

> 修改 #6、#13、#15 涉及 HTTP/Worker 共用路径时，按 AGENTS.md 要求同步验证
> Daemon、Cron、Script、HTTP 四条启动路径；涉及 Sidecar 协议字段变更时需同时
> 重编译 `Bin/` 二进制。
