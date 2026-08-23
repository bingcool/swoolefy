# Swoolefy Cron 技术优化方案

> 审查窗口：2026-08-07 ～ 2026-08-21  
> 基线分支：`swoolefy-6.2-x`  
> 基线提交：`b61b4744`（`feat: add Cron Maanger Admin UI`）  
> 范围：`Test/Module/Cron`（Web Admin / Agent API）与 `src/Worker/Cron`（执行引擎）  
> 定位：只优化现有架构的正确性、生产边界与可维护性，不新增产品功能。

## 1. 目标与边界

本方案保持现有主链路：

```text
Web Admin → cron_task → DB Polling → Scheduler → Execution
  local / fork / url → stdout/stderr → Execution Record → cron_task_log
  → Stats / Logs / Dashboard
```

优化目标不是重做 Cron，而是让这条链路满足以下生产不变量：

1. 配置修改不能泄露、破坏或意外覆盖敏感数据。
2. Active Job 始终只有一个有效 Schedule Timer；Disabled / Deleted Job 没有 Timer。
3. DB 或单条配置故障不清空 Last Known Good Runtime。
4. Execution Snapshot 创建后，本轮执行不受配置更新影响。
5. “进程已拉起”与“进程执行成功”必须是不同事实。
6. Execution Record、日志列表、统计与 Dashboard 使用同一结构化状态口径。
7. 一个 `node_id` 只能引用真实节点；一个 RunOnce request 只消费一次。
8. HTTP Worker 的进程本地 Runtime 不得伪装成 Cron Worker 的全局状态。

### 1.1 本次明确不新增

- 不增加 MQ。
- 不增加另一套 Scheduler。
- 不引入 Redis、Etcd、ZooKeeper、Consul 等分布式锁产品。
- 不把 `CronLocalProcess` 强制并入 `CronManager`。
- 不新增执行类型；继续保持 Local / Fork(Shell) / URL(HTTP)。
- 不引入 DAG、工作流、Leader Election 或独立调度服务。

### 1.2 实施原则

- 优先局部修复，保持 Controller → Service → Entity 与 Manager → Scheduler → Executor 分层。
- 新旧 Worker 滚动发布时，数据库字段和 API 必须向后兼容。
- 涉及数据库一致性的修改必须先补约束或事务，再切换调用路径。
- 每批独立可发布、可回滚；不得把三批绑定成一次大重构。

## 2. 应保留、不要重做的设计

| 设计 | 当前证据 | 保留理由与约束 |
|---|---|---|
| Last Known Good | `src/Worker/Cron/CronManager.php::syncFromFetcher()`、`applyRows()` | fetcher 异常或单行非法时保留既有 Runtime；不能改成失败即 clear all。 |
| ConfigDiff | `src/Worker/Cron/ConfigDiff.php::diff()` | 保持 ADD / UPDATE / DELETE / ENABLE / DISABLE / NOOP 最小变更，不全量重建。 |
| 单 Job 单 Timer | `src/Worker/Cron/CronScheduler.php::arm()` | 保持 one-shot Timer，内部先 clear 再 after；不改成每秒全量扫描。 |
| Execution Snapshot | `src/Worker/Cron/ExecutionSnapshot.php::create()`、`CronManager::runExecutionPipeline()` | 当前执行继续使用冻结定义，配置更新只影响下一轮。 |
| 结构化 ExecutionStatus | `src/Worker/Cron/ExecutionStatus.php`、`Test/Module/Cron/Service/CronTaskService.php::logCronTaskRuntime()` | 保持 `cron_task_log.status` 与同一 `exec_batch_id` 终态 upsert；message 只用于展示。 |
| RunOnce 精确消费 | `CronManager::consumeRunOnceRequests()`、`CronTaskManagerService::ackRunOnce()` | 按 requestId 逐条执行、逐条 ack；`SKIPPED` 不 ack。 |
| Local 独立调度 | `src/Worker/Cron/CronLocalProcess.php`、`src/Worker/Cron/README.md` | Local 继续走 `CrontabManager`；Fork / URL / DB Cron 才由 `CronManager` 调度。 |

RunOnce 的现有 ack 语义也应保留：`SUCCESS / FAILED / TIMEOUT / CANCELLED` 表示“请求已被处理”，因此 ack；只有 `SKIPPED` 表示尚未获得执行机会，不 ack。停用任务返回 `FAILED` 后 ack 是“请求已处理”，不是“执行成功”，本次不把它列为缺陷。

## 3. 优先级与三批落地

| 批次 | 定位 | 覆盖项 |
|---|---|---|
| 第一批：正确性止血 | 防止凭据破坏、错误成功状态、子进程死锁与生产输出污染 | O01～O04 |
| 第二批：生产边界 | 鉴权、重入、节点/日志一致性、配置语义与关键查询 | O05～O14 |
| 第三批：低风险收敛 | 减少无意义调度、原子化批处理、生命周期和文档/UI 收敛 | O15～O23 |

建议每批均按“测试先行 → 局部实现 → 灰度 → 指标观察 → 全量”执行。第一批完成前，不应把 Admin 与 Agent API 暴露到非可信网络。

## 4. 第一批：正确性止血（P0）

### O01 编辑 HTTP 任务会回写脱敏 Header

- **问题**：详情 DTO 把敏感 Header 替换为掩码，编辑页又把掩码作为真实值提交，导致数据库中的凭据被覆盖。
- **证据路径/符号**：`Test/Module/Cron/Dto/CronTaskManager/CronTaskRowDto.php::maskSensitiveHeaders()`；`Test/Module/Cron/static/cron-admin.html` 的 `Editor.init()`、`Editor.save()`；`CronTaskManagerService::updateTask()`。
- **触发条件**：打开已有 HTTP 任务编辑页，不修改 Header，修改其它字段后保存。
- **影响**：`Authorization`、Cookie、Token 等真实值被 `******` 一类展示值替换；下一轮 ConfigDiff 后 HTTP 任务鉴权失败，且原值不可从页面恢复。
- **局部修复方案**：
  1. UI 在加载详情时记录 `headersDirty=false`，仅用户实际修改 Header 编辑框时置为 true。
  2. 编辑保存且 `headersDirty=false` 时，从 PUT payload 中完全省略 `httpHeaders`，利用现有部分更新语义保留数据库值。
  3. 明确清空 Header 使用显式空对象/空数组并要求用户确认，不能用“未提交”表达清空。
  4. Service 增加防御校验：更新 payload 中若敏感键值等于服务端掩码哨兵，则拒绝请求并提示重新输入，禁止把展示值落库。
  5. API 继续只返回脱敏值，不为解决编辑问题而返回明文凭据。
- **验收测试**：
  - 原库 `Authorization=secret`，编辑名称后保存，数据库仍为 `secret`。
  - 修改 Header 为新值后保存，数据库变为新值。
  - 显式清空后数据库为空。
  - 直接向 API 提交敏感 Header 掩码值时返回业务错误。
- **优先级**：P0；第一批第 1 项。

### O02 Entity 生命周期钩子残留 `var_dump`

- **问题**：通用 Trait 在保存、插入、更新路径直接输出调试文本。
- **证据路径/符号**：`Test/Module/Cron/CronTaskEventTrait.php::onBeforeSave()`、`onBeforeInsert()`、`onAfterInsert()`、`onBeforeUpdate()`、`onAfterUpdate()`；该 Trait 被 `CronTaskEntity` 与 `CronTaskLogEntity` 使用。
- **触发条件**：任务 CRUD、执行日志 INSERT/UPDATE、Worker 写终态。
- **影响**：污染 HTTP 响应或 Worker stdout，破坏 JSON、增加日志噪声；高频执行日志会放大 I/O。
- **局部修复方案**：当前钩子没有业务逻辑，直接从两个 Entity 移除 Trait 引用并删除 Trait；若因兼容暂不删文件，则先将钩子和引用同时移除，不能保留空钩子或改成另一种直接输出。
- **验收测试**：
  - 创建、更新任务的响应可稳定 JSON decode。
  - 写入 RUNNING 与终态日志时 stdout/stderr 无类名和方法名。
  - Entity CRUD 回归通过，软删除行为不变。
- **优先级**：P0；第一批第 1 个发布可包含的独立清理。

### O03 Fork 的 SUCCESS 只表示“已拉起”

- **问题**：`CronForkProcess::executeCronSnapshot()` 在 `CronForkRunner::procOpen()` 异步投递后立即返回 `ExecutionResult::success('已拉起')`；`exec(..., async=true)` 也主要判断启动命令/PID。该 SUCCESS 不是子进程终态。
- **证据路径/符号**：`src/Worker/Cron/CronForkProcess.php::executeCronSnapshot()`；`src/Worker/Cron/CronForkRunner.php::procOpen()`、`exec()`；`src/Worker/Cron/ShellExecutor.php`。
- **触发条件**：fork 子进程成功创建，但业务脚本随后非零退出、崩溃，或 `procOpen()` 的内部 `goApp` 尚未完成时外层已返回。
- **影响**：Execution Record、成功率、重试、RunOnce ack 和 UI 均把“成功启动”误报为“执行成功”；`ExecutionGuard` 可能提前释放，`with_block_lapping` 与真实子进程生命周期脱节。
- **局部修复方案（推荐）**：
  1. 保持现有 Executor 架构，不新增调度器。
  2. 让 Runner 返回一个可等待的执行句柄/结果；读取真实 exit code 后，由同一 `ExecutionSnapshot` 写 SUCCESS/FAILED/TIMEOUT 终态。
  3. `ExecutionGuard` 的 running 生命周期覆盖子进程真实运行期；Runner 进程池与 Guard 共用同一真实 running 事实，避免各自提前结束。
  4. RUNNING 记录可在 PID 获得后更新 PID，终态仍按同一 `cron_id + exec_batch_id` upsert。
  5. 若短期必须坚持 fire-and-forget，则不得返回 SUCCESS：状态模型和 UI 应明确显示 `LAUNCHED`。但新增状态会扩大兼容面，因此优先采用“等待终态”的方案。
- **验收测试**：
  - 脚本启动成功后 `exit 0` → 最终 SUCCESS。
  - 脚本启动成功后 `exit 7` → 最终 FAILED、`exit_code=7`。
  - 长任务运行时 `with_block_lapping=1` 的下一触发为 SKIPPED。
  - RunOnce 在子进程终态前不被当作成功完成；终态后按现有消费规则 ack。
  - 配置在运行中变化不改变本轮 Snapshot。
- **优先级**：P0；第一批核心项。

### O04 stdout/stderr 未 drain

- **问题**：`procOpen()` 把 stdout/stderr 建为 pipe，callback 之后 finally 直接 `fclose`，没有保证持续读取；子进程大量输出时 pipe buffer 可写满并阻塞。
- **证据路径/符号**：`src/Worker/Cron/CronForkRunner.php::procOpen()` 中 descriptors、callback 参数 `$pipe1/$pipe2` 与 finally 的 `fclose()`。
- **触发条件**：脚本向 stdout/stderr 持续输出，超过操作系统 pipe buffer；callback 没有完整读取，或异步生命周期提前结束。
- **影响**：子进程卡死、`proc_close()` 等待、Guard 长期 running、Timer 后续持续 SKIPPED；日志缺失且无法得到真实 exit code。
- **局部修复方案**：
  1. `proc_open` 后立即关闭不使用的 stdin。
  2. stdout/stderr 设为 non-blocking，在同一协程内用 `stream_select`/短周期协程读取循环并分别 drain，直到 EOF 且进程退出。
  3. 输出按配置上限保留，超限截断并记录 `truncated=true`，但仍继续 drain，不能因不保存而停止读取。
  4. drain 完成后读取 `proc_get_status`/`proc_close` 的真实退出码，再关闭 pipe、写终态。
  5. 任意异常都在 finally 关闭已创建的资源；初始化失败时不得引用未定义的 `$pipes`/`$proc_process`。
- **验收测试**：
  - stdout、stderr 分别输出超过 pipe buffer 的脚本能结束且 exit code 正确。
  - stdout 与 stderr 同时高频输出不死锁。
  - 输出超限仅截断记录，不阻塞进程。
  - `proc_open` 失败和读取异常不泄漏 FD、不拖垮 Worker。
- **优先级**：P0；必须与 O03 同批设计和验证。

## 5. 第二批：生产边界（P1）

### O05 Cron Admin 与 Agent API 缺少有效鉴权

- **问题**：Cron 路由组只挂载 CORS 与永远返回 true 的测试中间件；Admin 页面本身也没有访问控制。
- **证据路径/符号**：`Test/Router/Module/CronManager.php`；`Test/Middleware/Group/GroupTestMiddleware.php::handle()`；`/cron-admin`、`/api/v1/tasks*`、`/api/v1/nodes*`、`/api/v1/agent/*`。
- **触发条件**：端口可从非可信网络访问。
- **影响**：匿名用户可创建/修改/删除任务、触发 RunOnce、读取脱敏配置；伪造 Agent 可拉任务、写心跳、伪造执行记录。
- **局部修复方案**：
  1. Admin 使用现有项目鉴权中间件，并按只读、任务写、节点管理、RunOnce、Runtime 查看拆分权限。
  2. Agent 使用独立机器凭据（固定 Token/HMAC 均可复用现有安全组件），校验时间戳和请求签名；Agent 凭据不能获得 Admin 权限。
  3. `/cron-admin` 与 API 同时保护，不能只隐藏页面。
  4. 鉴权失败在进入 Controller 前拒绝，日志中不打印 Token/Header 明文。
- **验收测试**：无凭据 401/403；Admin 只读账号不能写；Agent 凭据只能访问本节点 Agent 路由；签名过期/篡改被拒绝；合法请求兼容现有 DTO。
- **优先级**：P1，生产暴露前置门槛。

### O06 Config Polling 缺少重入保护

- **问题**：tick 每次 `goApp` 进入 `syncFromFetcher()`，当 DB 查询/RunOnce 执行超过 polling interval 时可并发同步。
- **证据路径/符号**：`src/Worker/Cron/CronManager.php::start()`、`syncFromFetcher()`；`src/Worker/Cron/SwooleCronTimer.php::tick()`。
- **触发条件**：慢查询、DB 抖动、大量 RunOnce、短 `cron_poll_interval`。
- **影响**：两个快照交错 apply，重复消费 RunOnce、Timer 反复 clear/arm、last sync 状态倒序覆盖。
- **局部修复方案**：在 `CronManager` 增加进程内 `syncing` 门闩；进入同步前原子检查并置位，finally 释放。重入 tick 只记录 `sync_skipped_reentrant` 并返回，不排队无限积压；`stop()` 后回调不得再 apply。该锁仅保护本进程，不引入分布式锁。
- **验收测试**：阻塞 fetcher 跨过 3 个 tick 时最大并发 fetch=1；只 apply 一份完整快照；异常后门闩释放；stop 与慢同步竞态不重新武装 Timer。
- **优先级**：P1。

### O07 节点引用缺少完整约束

- **问题**：任务创建/更新未确认 `node_id` 存在；节点删除不检查绑定任务；心跳底层可对未知 id 插入节点。
- **证据路径/符号**：`CronTaskManagerService::createTask()`、`updateTask()`、`deleteNode()`、`agentHeartbeat()`；`CronTaskService::ackNodeHeartbeat()`。
- **触发条件**：提交不存在的 nodeId、删除仍绑定任务的节点、伪造未知节点心跳。
- **影响**：孤儿任务永不被正确 Worker 拉取；节点元数据被心跳隐式创建；Admin 统计与实际部署不一致。
- **局部修复方案**：
  1. create/update 在同一事务内验证目标节点存在；nodeId 未变化可跳过重复查询。
  2. deleteNode 软删节点（写 deleted_at）；在事务中检查未删除任务引用，非零则拒绝并提示先迁移/删除任务。
  3. agentHeartbeat 只更新预先注册节点，未知节点返回错误，不自动创建。
  4. 条件允许时增加数据库外键或等价约束；若软删除模型不适合 FK，至少建立 `cron_task(node_id, deleted_at)` 索引并保留 Service 强校验。
- **验收测试**：不存在节点的创建/更新失败；有绑定任务的节点不能删；迁移后可删；未知心跳不新增行；合法心跳只更新目标节点。
- **优先级**：P1。

### O08 Agent 执行上报不是幂等写入

- **问题**：Agent report 无条件 INSERT，而 Worker 本地日志按 `cron_id + exec_batch_id` 查找后更新。
- **证据路径/符号**：`CronTaskManagerService::agentReport()`；`CronTaskService::logCronTaskRuntime()`；`CronTaskLogEntity`。
- **触发条件**：Agent 网络超时后重试、网关重放、RUNNING 与终态分别上报。
- **影响**：同一 Execution 多行，日志列表重复、统计重复计数、终态与 RUNNING 并存。
- **局部修复方案**：
  1. 统一 Agent 与 Worker 的 execution upsert 规则：非空 `exec_batch_id` 以 `cron_id + exec_batch_id` 为幂等键。
  2. 数据库增加唯一索引前先清理历史重复；配置变更日志的空 batch 不进入该约束，可采用 nullable batch 或生成列/业务分支适配数据库。
  3. 使用原子 upsert，不能继续“先查再插”承受并发竞态。
  4. 只允许状态向合法终态推进；迟到 RUNNING 不得覆盖 SUCCESS/FAILED。
- **验收测试**：同一报告重放 10 次只有一行；RUNNING→SUCCESS 更新同一行；SUCCESS 后迟到 RUNNING 不回退；不同 batch 正常分行。
- **优先级**：P1。

### O09 HTTP 超时语义不一致

- **问题**：Admin/DTO 默认 30 秒，Worker 转换时把小于 120 秒的值静默抬到 120 秒。
- **证据路径/符号**：`CronTaskRowDto::fromEntityRow()`；`cron-admin.html` Editor 默认值；`CronTaskService::fetchHttpCronTask()`；`TaskDefinition::fromArray()`。
- **触发条件**：保存 1～119 秒超时，尤其默认 30 秒。
- **影响**：实际失败等待时间是页面展示的 4 倍；重叠、Guard 与故障恢复时间被拉长。
- **局部修复方案**：定义唯一规则：`>0` 严格使用配置，`<=0/null` 使用统一默认值。默认值只在 PayloadBuilder/TaskDefinition 一个边界常量化；若产品要求最小值，应在 Admin 校验并拒绝，而不是 Worker 静默改写。滚动发布先让新 Worker尊重旧库正值，再统一 UI 默认。
- **验收测试**：配置 30 实际 transport timeout=30；配置 120 为 120；空值使用统一默认；非法负数在写入端失败或明确回退。
- **优先级**：P1。

### O10 Polling RunOnce N+1 与 `SELECT *`

- **问题**：每轮先 `SELECT *` 拉任务，随后每个任务调用 `listPendingRunOnceIds()`，形成 N+1。
- **证据路径/符号**：`Test/Module/Cron/Service/CronTaskService.php::fetchCronTask()`、`fetchShellCronTask()`、`fetchHttpCronTask()`、`listPendingRunOnceIds()`。
- **触发条件**：同节点任务数增长、较短 polling interval、HTTP body/header 较大。
- **影响**：每轮约 `1 + N` 查询，DB QPS 与序列化内存线性增长；慢轮询又放大 O06 重入。
- **局部修复方案**：
  1. 任务查询明确列出 TaskDefinition 与日志转换实际需要字段，不取无关列。
  2. 一次查询全部目标 task id 的 pending request，按 `cron_id, id` 排序后在 PHP 分组。
  3. Shell 与 HTTP 拉取共用批量 pending 映射，避免各自重复查询。
  4. 暂不做增量 CDC/MQ，仍保持全量 DB Polling。
- **验收测试**：10/1000 个任务每轮查询数保持常数级；每个 requestId 顺序与数量不变；大 body/header 下内存峰值下降；禁用任务仍被拉取供 Diff 识别 DISABLE。
- **优先级**：P1。

### O11 缺失 `CRON_NODE_ID` 时没有 fail closed

- **问题**：配置直接读取 `env('CRON_NODE_ID')`；nodeId 为 null 时 `CronManager` 不做节点过滤，DB fetch 也可能产生模糊查询语义。
- **证据路径/符号**：`Test/WorkerCron/conf/schedule_fork_conf.php`、`schedule_url_conf.php`；`CronManager::applyRows()` 的 nodeId 过滤。
- **触发条件**：生产漏配、变量名拼错、空字符串。
- **影响**：Worker 可能不拉任务、拉错任务，或在静态/组合配置下跨节点执行；心跳也无法正确归属。
- **局部修复方案**：DB-driven Fork/URL 配置启动时校验 `CRON_NODE_ID` 为正整数，否则抛出明确配置异常并拒绝启动调度与心跳。纯静态 task_list 若确实允许无节点，应显式配置 `node_scope=local` 一类既有配置分支或在装配处清晰区分，不能由“环境变量缺失”隐式进入。
- **验收测试**：缺失、空、0、非数字均启动失败且无 Timer；合法 id 只拉本节点；错误节点不产生心跳。
- **优先级**：P1。

### O12 跨日时间窗无法命中

- **问题**：`22:00-02:00` 的 start/end 都锚定同一天，得到 end < start，当前闭区间比较永远为 false。
- **证据路径/符号**：`src/Worker/Cron/TimeWindowFilter.php::resolveRange()`、`inWindow()`。
- **触发条件**：`cron_between` 或 `cron_skip` 使用跨午夜时刻窗。
- **影响**：夜间允许窗任务全部 SKIPPED，或夜间禁用窗失效；RunOnce 同样受影响。
- **局部修复方案**：对纯时刻窗，若 `endTs < startTs`，按跨日环形窗口判断：`now >= startToday || now <= endToday`；实现上可拆成两段，或根据 now 选择 start 前一天/end 后一天。完整日期时间仍按绝对区间，不自动跨日。边界继续为闭区间，与现有语义兼容。
- **验收测试**：`22:00-02:00` 在 21:59 false、22:00 true、23:59 true、00:00 true、02:00 true、02:01 false；between 与 skip 均覆盖；非跨日不回归。
- **优先级**：P1。

### O13 删除任务后 pending RunOnce 可能永久滞留

- **问题**：软删除后 fetcher 不再返回任务行，pending request 失去消费入口。
- **证据路径/符号**：`CronTaskManagerService::deleteTask()`、`enqueueRunOnce()`、`ackRunOnce()`；`CronTaskService::fetchCronTask()` 使用 `queryNotDeleted()`。
- **触发条件**：RunOnce 已入队但 Worker 尚未轮询，随后删除任务。
- **影响**：`cron_task_run_request.consumed_at` 永久为空，运维误判积压，数据持续增长。
- **局部修复方案**：`deleteTask()` 在一个数据库事务内软删任务，并把该任务所有 pending request 标记为已处理；若现表只有 `consumed_at`，先复用该字段并在审计日志注明“task deleted”。不得让 Worker执行已删除任务，也不需要新增队列系统。
- **验收测试**：入队后删除，pending 数归零；删除与轮询并发时请求最多执行一次；已经 consumed 的时间不被覆盖；事务失败时任务和 pending 状态一起回滚。
- **优先级**：P1。

### O14 日志列表与统计口径不一致

- **问题**：`taskStats()` 只统计 `exec_batch_id <> ''` 的 Execution 行并使用半开结束时间；`taskLogs()` 默认可包含 batch 为空的配置变更日志，结束时间使用 `<=`，非法 status 字符串还会退化为“不筛选”。
- **证据路径/符号**：`CronTaskManagerService::taskLogs()`、`taskStats()`、`newExecutionStatsQuery()`；`ExecutionStatus::fromName()`。
- **触发条件**：任务有 ADD/UPDATE/DELETE 日志、指定 end、或传入未知 status。
- **影响**：列表 total 与统计 total 无法对账；边界时刻记录归属不同；错误筛选值返回全量数据。
- **局部修复方案**：
  1. “执行日志列表”默认增加 `exec_batch_id <> ''`，配置变更审计若需展示继续走独立既有入口/明确类型，不混入 Execution。
  2. 统一时间范围为 `[start, end)`。
  3. status 非空但无法解析时返回参数错误，不得忽略。
  4. 所有页面继续以结构化 `status` 为准，不回退解析 message。
- **验收测试**：同一 task/time filter 下列表 total 等于 stats total；配置变更行不计入；end 边界只归入后一窗口；每个合法状态列表数等于统计分项；非法状态 4xx/业务错误。
- **优先级**：P1。

## 6. 第三批：低风险收敛（P2）

### O15 `updated_at` 导致无意义 Timer 重建

- **问题**：`updatedAt` 进入 fingerprint，任何仅更新时间变化都触发 UPDATE、clear、重新 arm。
- **证据路径/符号**：`src/Worker/Cron/TaskDefinition.php::fingerprint()`；`ConfigDiff::diff()`。
- **触发条件**：仅修改描述、审计字段或其它不影响调度/执行的元数据。
- **影响**：nextRunAt 被重算、Timer 抖动，接近触发点时增加漏跑/重复竞态窗口。
- **局部修复方案**：从 fingerprint 移除 `updatedAt`；只保留 expression、执行类型、命令、状态以外的执行参数、时间窗、HTTP 配置、retry、timezone 等真实行为字段。status 继续独立走 ENABLE/DISABLE。
- **验收测试**：仅 updated_at 变化得到 NOOP 且 timerId/nextRunAt 不变；command/expression/timeout 变化仍 UPDATE；status 变化仍正确 ENABLE/DISABLE。
- **优先级**：P2。

### O16 过期 nextRunAt 被压成 1ms

- **问题**：`CronScheduler::arm()` 对任何过期 `$next` 使用 `max(1, ...)`，把异常/陈旧计划点变成几乎立即触发。
- **证据路径/符号**：`src/Worker/Cron/CronScheduler.php::arm()`。
- **触发条件**：事件循环暂停、时钟跳变、传入陈旧 `$fromTimestamp`、Schedule 实现异常返回过去时间。
- **影响**：连续 1ms 触发、CPU/日志突增；错误 Schedule 可形成密集循环。
- **局部修复方案**：若 `next <= now`，以当前 now 重新计算严格未来的 next；设置小的有限循环上限并验证单调递增，仍不能得到未来值则抛调度异常，由现有 `recordSchedulerError + reconcileMissingTimers` 处理。不要用 1ms 掩盖非法结果。
- **验收测试**：陈旧 from 只 arm 一个未来 Timer；恶意/错误 Schedule 返回过去值时不忙循环；正常 interval/cron 对齐规则不变。
- **优先级**：P2。

### O17 批量启停不是原子操作

- **问题**：`batchSwitchStatus()` 循环调用单条保存，中途失败会留下部分更新。
- **证据路径/符号**：`CronTaskManagerService::batchSwitchStatus()`、`switchTaskStatus()`。
- **触发条件**：ids 中存在不存在/已删除任务、数据库中途异常。
- **影响**：API 报失败但部分任务已启停，下一轮 Diff 已应用部分状态。
- **局部修复方案**：先规范化并去重 ids，在事务内锁定/验证全部可见任务，再一次批量 UPDATE；受影响数量必须等于目标数量，否则回滚。返回仍使用现有 `BatchStatusResultDto`。
- **验收测试**：全部合法一次成功；任一非法全部不变；重复 id 不影响计数；DB 异常全部回滚。
- **优先级**：P2。

### O18 节点列表逐条 count 任务

- **问题**：`listNodes()` 取节点后逐节点 count，形成 N+1。
- **证据路径/符号**：`CronTaskManagerService::listNodes()`。
- **触发条件**：节点数量增长、Dashboard/Nodes 页面频繁刷新。
- **影响**：节点数 N 对应 N+1 查询，拖慢 Admin 并增加 DB 连接占用。
- **局部修复方案**：一次按 `node_id GROUP BY` 聚合未删除任务，再映射到节点；或单 SQL LEFT JOIN 聚合。无任务节点必须返回 0。
- **验收测试**：任意节点数查询数常数级；软删除任务不计数；无任务为 0；结果与旧实现一致。
- **优先级**：P2。

### O19 CronForkRunner 静态实例与任务生命周期脱节

- **问题**：Runner 按名称 md5 存在静态 `$instances`，CronManager DELETE/rename/stop 不自动释放；同名任务重建可能复用旧并发与进程池状态。
- **证据路径/符号**：`CronForkRunner::$instances`、`getInstance()`、`removeRunner()`；`CronForkProcess::executeCronSnapshot()`；`CronManager::applyDelete()`、`stop()`。
- **触发条件**：任务删除、改名、并发配置变化、Worker 长期不重启。
- **影响**：内存和 GC tick 状态残留；Guard 与 Runner 对 running 的判断不一致；新任务继承旧 Runner。
- **局部修复方案**：Runner key 改用稳定 jobId/cronTaskId 而非名称；CronManager/Executor 在 DELETE 且真实子进程全部结束后释放 Runner，Worker stop 全量释放；配置 UPDATE 时显式刷新可变并发参数。与 O03 共用真实执行生命周期。
- **验收测试**：删除未运行任务立即释放；运行中删除在终态后释放；同名新任务不继承旧池；stop 后实例与 GC timer 均为零。
- **优先级**：P2，但应与 O03 的接口设计一起确定。

### O20 pid 文件等待使用阻塞 `sleep`

- **问题**：协程执行路径等待 pid 文件时调用原生 `sleep(1)` 最多 5 次。
- **证据路径/符号**：`CronForkRunner::procOpen()` 的 pid-file loop。
- **触发条件**：swoolefy script 模式、pid 文件生成较慢或失败。
- **影响**：阻塞 Worker 线程/事件循环，影响其它 Timer、HTTP 与 Polling。
- **局部修复方案**：协程内改用 `Swoole\Coroutine\System::sleep()`，采用 50～200ms 有界轮询并使用单调截止时间；非协程路径保留兼容等待。超时明确返回 PID 未确认状态并进入失败处理，不能继续伪报成功。
- **验收测试**：等待 pid 文件期间其它 Timer 正常触发；文件及时出现能读取；超时总时长有上限；非协程单测不崩溃。
- **优先级**：P2。

### O21 Web Admin API 文档与实际路由漂移

- **问题**：架构文档多处使用 `/tasks/{id}`、`/nodes/{id}`、`POST /tasks/{id}/run` 等 REST 路径，实际框架路由使用 `/tasks/detail?id=`、`/nodes/detail?id=`、`POST /tasks/run`。
- **证据路径/符号**：`docs/Swoolefy-Cron-WebAdmin-API-Architecture.md`；`Test/Router/Module/CronManager.php`；`CronTaskManagerController` PHPDoc。
- **触发条件**：使用文档联调、生成客户端、编写监控探针。
- **影响**：请求 404/方法错误；同一仓库出现两套契约。
- **局部修复方案**：以实际精确路由为当前契约，更新文档的路由树、curl、权限矩阵和返回示例；在文档中把理想 REST 路径标注为“设计映射”而非已实现。增加路由契约测试从 Router 断言 method/path/controller。
- **验收测试**：文档全部 curl 与 Router 对照通过；不存在宣称已实现但未注册的路径；CI 路由快照变更需显式更新文档测试。
- **优先级**：P2。

### O22 Runtime Overview 的进程本地语义易误导

- **问题**：HTTP Worker 通常读不到 Cron Worker 的 `RuntimeRegistry`；接口混合 DB 任务数、Agent 心跳与本进程 snapshot，用户容易把 running=0 理解为全局无任务运行。
- **证据路径/符号**：`CronTaskManagerService::runtimeOverview()`；`RuntimeRegistry::cronSnapshot()`；`src/Worker/Cron/README.md` 的 Runtime Diagnostics 说明；Admin `Runtime` 页面。
- **触发条件**：从 Web Admin 查看 Runtime，而 Cron 在独立 Worker 进程运行。
- **影响**：错误运维判断，可能在任务实际运行时重复触发或重启 Worker。
- **局部修复方案**：保持现有进程本地 Runtime，不新建跨进程采集系统；API/UI 明确区分 `source=db|process_local`、`runningKnown=false`，未知值展示“不可用/非本进程”，不能展示为 0；节点在线仅代表心跳新鲜，不等于 Scheduler 正常。
- **验收测试**：HTTP Worker 无 snapshot 时 running 显示未知而非全局 0；同进程测试快照时标注 process-local；DB count 与 runtime count 标签不混淆。
- **优先级**：P2。

### O23 Admin UI 依赖公共 CDN

- **问题**：页面运行时从 unpkg 加载 Vue、Vue Router、Element UI 与 CSS。
- **证据路径/符号**：`Test/Module/Cron/static/cron-admin.html`。
- **触发条件**：生产无公网、CDN 故障/劫持、CSP 禁止第三方资源。
- **影响**：Admin 白屏；供应链内容与可用性不受仓库发布控制。
- **局部修复方案**：锁定当前版本，将构建产物或 vendor 静态文件随应用发布并改为同源路径；记录许可证与校验值；配置 CSP 仅允许 self。此项不要求重写前端框架。
- **验收测试**：断网环境页面完整可用；浏览器 Network 无公共域名；静态资源带长缓存和版本指纹；CSP `script-src 'self'` 下可运行。
- **优先级**：P2。

## 7. 分批实施与发布顺序

### 7.1 第一批：正确性止血

1. O02 清除生命周期输出，先消除响应/日志污染。
2. O01 修复 Header 未修改时的省略提交，并增加 Service 防御。
3. 联合实现 O03、O04：Runner drain、等待真实终态、Guard 生命周期对齐。
4. 回归 Execution Snapshot、RunOnce ack、retry、重叠守卫与 Worker 异常隔离。

**批次退出标准**：

- HTTP Header 不发生静默破坏。
- Fork 的 SUCCESS 必须来自真实 exit code 0。
- 大量 stdout/stderr 不阻塞。
- 单 Job 的 Runner/Executor 异常不导致 Worker 退出。

### 7.2 第二批：生产边界

1. O05 鉴权先行，Admin 与 Agent 使用不同信任域。
2. O06 Polling 防重入。
3. O07、O08、O13 数据一致性改动：先清理数据和建约束，再切服务逻辑。
4. O09～O12 统一 Worker 配置语义与调度边界。
5. O10、O14 收敛高频查询和 Execution 口径。

**批次退出标准**：

- 未认证请求不能访问 Cron 控制面。
- Polling 最大并发为 1。
- 节点、Execution、RunOnce 无孤儿/重复记录。
- HTTP timeout、跨日窗口与日志统计可以通过契约测试对账。

### 7.3 第三批：低风险收敛

1. O15、O16 减少 Timer 抖动和密集触发。
2. O17、O18 优化 Admin 原子性与查询。
3. O19、O20 收敛 Runner 资源生命周期。
4. O21～O23 同步文档、Runtime 展示和静态资源。

**批次退出标准**：

- 元数据更新不重建 Timer，过期计划不触发 1ms 循环。
- 批量启停全成全败，节点列表无 N+1。
- Worker stop 后 Runner/Timer/FD 无残留。
- 文档、实际路由和离线 Admin 可用性一致。

## 8. 兼容性与回滚

### 8.1 API 兼容

- 任务 CRUD、RunOnce、节点与日志 API 路径保持不变；只收紧鉴权、非法 status 和掩码 Header 写入。
- Header 更新继续使用现有部分更新能力；省略字段表示保留，显式空值表示清空。
- Runtime DTO 若增加 `runningKnown/source`，旧字段暂时保留一个发布周期，前端先适配新字段。

### 8.2 数据兼容

- Execution 唯一约束上线前，先按 `cron_id + exec_batch_id` 合并重复数据并保留最新合法终态。
- Agent/Worker 混合版本期间，新端必须能接受旧端缺少可选结构化字段的报告，但不得从 message 推断新统计。
- 删除任务清理 pending RunOnce 复用 `consumed_at` 时，无需改 Worker 协议。

### 8.3 Worker 滚动发布

- 先发布支持新日志 upsert/状态推进规则的服务端，再发布 Agent。
- O03/O04 应以单节点灰度，重点观察 running、SKIPPED、执行耗时与 FD 数。
- 任一批回滚不得回滚数据库数据清理；代码回滚前确认旧代码能容忍新增索引/可选字段。

## 9. 测试矩阵与生产验收

### 9.1 单元与集成测试

- Web Admin：Header dirty/omit/clear/mask-reject、节点引用、批量事务、日志过滤。
- Scheduler：跨日窗口、过期 nextRunAt、fingerprint NOOP、Polling 重入。
- Fork：exit 0/非零、双流大输出、pid 文件延迟、异常清理、真实 running。
- Execution：Agent 重放、状态单向推进、Worker/Agent 同一幂等键。
- RunOnce：逐 requestId 消费；SUCCESS/FAILED/TIMEOUT/CANCELLED ack，SKIPPED 不 ack；删除任务清 pending。
- 契约：Router method/path 与文档示例一致。

### 9.2 压力与故障注入

1. 1000 个任务、5 秒 Polling，确认查询数常数级且无重入。
2. DB 延迟超过 poll interval，Runtime 保持 Last Known Good，Timer 不重复。
3. Fork 每路输出超过 pipe buffer，进程均结束且 FD 回落。
4. Agent 同一报告并发重放 100 次，数据库只有一条 Execution。
5. Worker 在子进程运行中接收 UPDATE/DELETE，当前 Snapshot 完成、未来 Timer 正确收敛。

### 9.3 生产观察指标

- Config sync duration、reentrant skip、last error。
- Timer count 与 enabled job count。
- Execution RUNNING 持续时间、SUCCESS/FAILED/SKIPPED、未知终态数。
- RunOnce pending 数与最老等待时间。
- Cron Worker FD、子进程数、stdout/stderr 截断次数。
- Agent report 冲突/upsert 次数与鉴权失败数。

## 10. 完整覆盖清单

| 编号 | 核心优化点 | 方案章节 | 状态 |
|---|---|---|---|
| 1 | 编辑 HTTP 任务回写脱敏 Header | O01 | 已覆盖 |
| 2 | Entity 生命周期钩子 `var_dump` | O02 | 已覆盖 |
| 3 | Fork SUCCESS 只是已拉起 | O03 | 已覆盖 |
| 4 | stdout/stderr 未 drain | O04 | 已覆盖 |
| 5 | Admin / Agent 无有效鉴权 | O05 | 已覆盖 |
| 6 | Config Polling 重入 | O06 | 已覆盖 |
| 7 | 节点引用约束 | O07 | 已覆盖 |
| 8 | Agent report 幂等 | O08 | 已覆盖 |
| 9 | HTTP timeout 30/120 不一致 | O09 | 已覆盖 |
| 10 | Polling RunOnce N+1 / SELECT * | O10 | 已覆盖 |
| 11 | CRON_NODE_ID fail closed | O11 | 已覆盖 |
| 12 | 跨日时间窗 | O12 | 已覆盖 |
| 13 | 删除任务遗留 pending RunOnce | O13 | 已覆盖 |
| 14 | taskLogs / taskStats 口径 | O14 | 已覆盖 |
| 15 | updated_at fingerprint | O15 | 已覆盖 |
| 16 | 过期 nextRunAt → 1ms | O16 | 已覆盖 |
| 17 | 批量启停非原子 | O17 | 已覆盖 |
| 18 | listNodes N+1 | O18 | 已覆盖 |
| 19 | Runner 静态生命周期 | O19 | 已覆盖 |
| 20 | pid 文件阻塞 sleep | O20 | 已覆盖 |
| 21 | API 文档与路由漂移 | O21 | 已覆盖 |
| 22 | Runtime Overview 进程本地语义 | O22 | 已覆盖 |
| 23 | Admin UI 公共 CDN | O23 | 已覆盖 |

## 11. 最终结论

当前 Cron 的主架构方向正确，不需要推倒重来。优化后的目标仍然是：

```text
DB 配置
  → Last Known Good / ConfigDiff
  → 单 Job 单 one-shot Timer
  → Execution Snapshot
  → 现有 Local / Fork / URL Executor
  → 真实终态 Execution Record
  → 同口径 Logs / Stats / Dashboard
```

第一批先保证敏感配置和 Fork 执行事实正确；第二批补齐生产鉴权、重入与数据边界；第三批再做 Timer、查询、资源和文档收敛。整个过程中不增加 MQ、不新增 Scheduler、不引入分布式锁产品、不合并 Local 产品线，也不扩展执行类型。
