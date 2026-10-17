# src/Support 潜在 Bug 分析报告（2026-10-17）

- 审查日期：2026-10-17
- 代码分支：`swoolefy-6.2-x`
- 审查范围：`src/Support` 全部模块（约 3 万行、28 个能力目录）
- 审查方式：源码级只读分析 + 关键疑点实测/追源码验证
- 结论说明：所有发现均对照源码行号核实，并主动排除 3 个初期误报（见文末）
- 交付边界：本报告只做分析，未改动任何框架代码
- 修复进展：#3 RedisRunStore.listWaiting 已于 2026-10-17 修复（见该条「✅ 已修复」说明）；#2 TenantScope 经复核在当前 tenantId 约束下不适用，未修改代码；其余条目仍为分析结论

---

## 🔴 MEDIUM-HIGH

### 1. OutboundUrlGuard — SSRF 语义盲区（可被绕过内网探测）

- 文件：`src/Support/Security/OutboundUrlGuard.php`（`isPrivateOrLoopback()` L110-130）
- 现象：
  - `isPrivateOrLoopback()` 只对**字面 IP** 判私网，**不做 DNS 解析**。攻击者用一个解析到 `127.0.0.1` / `169.254.169.254`（云元数据）的域名即可绕过 L74 的私网拦截。
  - IPv6 分支（L120-127）只匹配 `::1`/`fe80:`/`fc`/`fd` 前缀，漏判 **IPv4-mapped-IPv6** `::ffff:127.0.0.1`（走进 `filter_var(...IPV6)` 但不命中任何前缀 → 判定为公网放行）。
  - 十进制/八进制 IP 写法（`2130706433`）因 `filter_var(FILTER_VALIDATE_IP)` 拒绝非点分格式，落到 L129 `return false`（放行），依赖下游是否再解析。
  - 私网段清单（L144-151）缺 `100.64.0.0/10`(CGNAT)、`192.0.0.0/24`、`198.18.0.0/15`。
- 影响：MCP Server url、LLM baseUri 等出站连接的 SSRF 防护形同虚设。
- 缓解现状：`requireAllowlist` 生产 fail-closed（L79）能兜住大部分场景，但默认 `false` 时「公网域名 + 私网解析」可穿透。
- 修复方向：对 host 做 DNS 解析后按解析出的 IP 判定；补齐 IPv4-mapped-IPv6、CGNAT 段；或强制要求 allowlist。

---

## 🟠 MEDIUM

### 2. TenantScope — sanitize 折叠 + 复合键歧义导致跨租户串数据（当前 tenantId 约束下不适用）

- 文件：`src/Support/TenantScope.php`（`sanitize()` L25、`scopedKnowledgeBase()` L61）
- 现象：
  - `sanitize()` 把所有非 `[a-zA-Z0-9_-]` 字符替换为 `_`。
  - `scopedKnowledgeBase()` 返回 `sanitize($tenant) . '_' . sanitize($kb)`，拼接符也是 `_`，**无法区分字段边界**：
    - `tenant="a", kb="b_c"` → `a_b_c`
    - `tenant="a_b", kb="c"` → `a_b_c`
  - 两个不同租户命中同一知识库物理名 → **知识库隔离失效**（RAG 数据跨租户泄漏）。
  - `redisChatKeyPrefix`（L84）+ threadId 存在同类拼接歧义。
- 影响：多租户 RAG / ChatHistory 隔离可被构造 tenantId/kb 名绕过。
- 修复方向：拼接分隔符使用不会出现在 sanitize 结果里的字符（如 `:`），或对 tenant 做不可逆映射（hash）后再拼。
- ✅ 结论（2026-10-17 复核）：本项在**当前 tenantId 取值约束下不适用**，不修改代码。触发的两个前提都不成立：
  - ① sanitize 折叠：需 tenantId 含 `_`/`-`/字母数字以外的字符才会折叠。`TenantID100001`、`100000` 全为字母数字 → `sanitize` 空操作，不折叠。
  - ② 复合键边界歧义：拼接分隔符就是 `_`，跨租户碰撞的**充要条件是某个 tenantId 自身含 `_`**。当前 tenantId 不含 `_` → 边界由第一个 `_` 唯一确定 → 不同租户前缀必不同 → 不可能碰撞（kb 名带 `_` 不影响，它在边界之后）。
- ⚠️ 唯一前提（caveat）：tenantId 会经 `x-tenant-id` 透传头进入 `FrameworkContext::getTenantId()`。上述结论依赖 tenantId 不会被塞进含 `_` 或怪字符的值：若该头由网关/鉴权后按登录身份注入、客户端不可伪造 → 完全安全；若为客户端自由输入 → 建议入口加 `^[A-Za-z0-9]+$` 校验。
- ℹ️ 回退说明：本次一度尝试将租户段改为定长哈希，但会改变存量知识库表名/chat key 格式需迁移；在上述 tenantId 约束下本就无需修复，相关改动已全部 git 回退，代码保持原状。

### 3. RedisRunStore.listWaiting — 单个损坏 run 抛断整个 HITL 等待列表　✅ 已修复（2026-10-17）

- 文件：`src/Support/Workflow/Engine/RedisRunStore.php`（`listWaiting()` L242-264，`find()`/`hydrate()` L230-239）
- 现象：循环里 `$this->find($runId)`（L247）→ `hydrate()` 对损坏 payload 或未注册 runId 抛 `WorkflowRuntimeException`（L232），**循环体没有逐项 try/catch**。
- 影响：keyspace 里只要残留一个 payload 损坏 / 定义已下线的 run，整个人工审批（HITL）等待列表接口全部 500，无法降级。
- 修复方向：`listWaiting` 内对每个 run 的读取包 try/catch，坏项跳过并记 warning。
- ✅ 已修复：`listWaiting` 循环内将 `$this->find($runId)` 用 `try/catch (Throwable)` 包裹，坏项（payload 损坏或定义已下线导致 `hydrate()` 抛错）跳过并经 `SupportLog::warning('workflow', ...)` 记录 `runId` 与原因，其余等待项照常返回，接口可降级。`scanKeys()` 仍在循环前执行，Redis 整体不可用等基础设施错误仍会抛出（符合预期）。

---

## 🟡 LOW-MEDIUM

### 4. RedisRunStore.scanKeys — 全键空间 SCAN + N 次 GET 的性能地雷

- 文件：`src/Support/Workflow/Engine/RedisRunStore.php`（`scanKeys()` L266-316）
- 现象：`scanKeys()` 用 `MATCH {prefix}*` 扫描**整个 keyspace**（成本随库内 key 总量线性增长），`listWaiting()` 再对每个 runId 单独 `GET` → O(N) 次往返，无 pipeline、无分页上限。
- 影响：run 数量大或共享 Redis 时，HITL 列表接口延迟随数据量恶化，可能长时间占用协程 IO 时间片。
- 修复方向：维护一个 waiting 二级索引 Set/ZSet；或对 find 结果做 pipeline/mget 批量读取。

### 5. WorkflowEngine.applyCancellationIfRequested — CANCELLED 分支 fire 不 persist，违反自述不变式

- 文件：`src/Support/Workflow/Engine/WorkflowEngine.php`（`applyCancellationIfRequested()` 约 L602-614，不变式说明约 L214-217）
- 现象：CANCELLED 分支调用 `fireRunComplete($run)` 在内存对象上置闩 `_runCompleteFired`，但**没有把闩状态持久化**，与自述不变式「先置闩再 persist，保证 store 可回读、recovery 不得二次 fire」矛盾。
- 影响（受限可达）：同进程内靠内存闩幂等，无碍；仅当 recovery scanner 从 store 重载这条 CANCELLED run 时，因持久化闩缺失可能**重复触发 run 完成回调（重复通知/重复补偿）**。属潜在竞态，非默认必现。
- 修复方向：CANCELLED 分支同样走 persist 后再 fire，或 fire 前 CAS 落盘闩标记。

---

## 🟢 LOW

### 6. SqlChatHistoryArchive — 表名未做标识符校验

- 文件：`src/Support/Neuron/Memory/SqlChatHistoryArchive.php`（`INSERT INTO %s` L54/L93 等）
- 现象：直接把 `$this->table` sprintf 进 SQL，**无** `assertSafeIdentifier`（对比 `PgVectorStore` L90/L312 有），也无 `sanitizeTableName`（对比 `SqlChatHistory` 有）。
- 缓解：表名来自配置而非请求、值均走绑定参数、limit 用 `%d` → 仅当配置本身被污染才可注入，风险低。
- 修复方向：对齐其他两处，构造期用正则 `/^[A-Za-z_][A-Za-z0-9_]*$/` 校验表名。

### 7. DocumentOcr\WorkDirectory — 崩溃 job 的临时子目录无 TTL 清扫

- 文件：`src/Support/DocumentOcr/WorkDirectory.php`（`createJobDir()` L51-62，`removeDir()` L67-81）
- 现象：`createJobDir()` 生成 `job_YmdHis_hex` 唯一子目录，依赖调用方 finally 里 `removeDir()` 清理。进程崩溃 / worker 被 kill 时子目录残留，且无启动期或定期 TTL 清扫。
- 影响：磁盘缓慢泄漏。隔离与路径越界防护（L76）本身没问题。
- 修复方向：启动或定期按目录时间戳清扫超期 `job_*` 子目录。

### 8. InMemoryRunStore — 无淘汰 + 返回共享引用

- 现象：按 contract 仅用于测试/单进程 demo；无上限增长、`find()` 返回可变共享对象。
- 影响：若被误接到长驻内存服务会 OOM，且存在并发写串扰。属使用约束，非实现 bug。
- 修复方向：文档明确「仅限测试」；或返回深拷贝。

---

## ⚪️ 注意点（默认配置下非 bug）

### 9. RetryExecutor / DbRunStore 的 `usleep` 阻塞风险 —— 仅在特定条件下成立

- 文件：`src/Support/Workflow/Engine/RetryExecutor.php`（L54 `usleep($delayMs*1000)`）、`DbRunStore.php`（L87/L187）
- 已实测确认：框架默认 `hook_flags = SWOOLE_HOOK_ALL`（含 `HOOK_BLOCK_FUNCTIONS`）会 hook `usleep`，**协程内不阻塞 Worker**。
- 仅当用户显式调低 `hook_flags`，或在**协程上下文外**（Master/非协程进程）触发重试退避时，`usleep` 才会真正阻塞。建议在文档标注该前提。

---

## ✅ 已主动排除的 3 个误报

| 初期疑点 | 排除依据 |
|---|---|
| `usleep` 会阻塞整个 Worker | 实测 SWOOLE_HOOK_ALL 下 20×usleep(0.2s) 并发总耗时 = 0.2s，被 hook 协程化 |
| `WorkflowEngine::start()` 只跑 `entryNodes()[0]` 会漏多根 DAG | `WorkflowCompiler` L198-201 强制 `count>1` 抛错，运行器恒单入口 |
| `FrameworkContext`/`HeaderContext` 在 `goApp` 子协程丢失租户 | `Core/Coroutine/Context.php`：二者写入同一原生协程 ArrayObject（均存 array），`snapshot()`/`filterPropagatable` 一致透传 |

---

## 工程质量评价（未发现明显 bug 的模块）

以下模块精读后实现严谨，无明显 bug：

- `Auth`（JwtAuthGuard / AuthUser，快照禁存 object 到位）
- `Job`（JobRunner / JobRetryPolicy）
- `Queue`（ListQueueConsumerProcessHandler，优雅 drain）
- `Rocketmq`（RocketmqConsumeSession，invisibleMs 夹取 / 续租 / ack 幂等处理规范）
- `Nacos`（ServiceRegister，心跳轻重升级、Timer tick/clear 成对）
- `Rag\PgVectorStore`（标识符 + 距离运算符白名单校验）

---

## 优先级建议

1. 先修 **#1 SSRF**（安全面）。#2 租户碰撞在当前 tenantId 约束（唯一、`[A-Za-z0-9]`、不含 `_`）下不适用，仅需保证透传头不可被客户端伪造。
2. 再处理 **#3 listWaiting 容错**（可用性），顺带 **#4 二级索引**。
3. **#5 CANCELLED 闩** 视 recovery 是否上线决定优先级。
4. **#6~#9** 属加固与文档项，可批量小改。
