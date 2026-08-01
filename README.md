# swoolefy
```
  ______                                _           _ _ _ _
 /  ____|                              | |         |  _ _ _|  _   _
|  (__     __      __   ___     ___    | |   ___   | |       | | | |
 \___  \   \ \ /\ / /  / _ \   / _ \   | |  / _ \  | |_ _ _  | | | |
 ____)  |   \ V  V /  | (_) | | (_) |  | | | ___/  |  _ _ _| | |_| |
|_____ /     \_/\_/    \___/   \___/   |_|  \___|  | |        \__, |
                                                   |_|           | |
                                                              __ / |
                                                             |_ _ /
```  

[![License](https://img.shields.io/packagist/l/bingcool/swoolefy.svg)](https://packagist.org/packages/bingcool/swoolefy)
[![Latest Stable Version](https://img.shields.io/packagist/v/bingcool/swoolefy.svg)](https://packagist.org/packages/bingcool/swoolefy)
[![PHP Version Require](https://img.shields.io/packagist/php-v/bingcool/swoolefy.svg)](https://packagist.org/packages/bingcool/swoolefy)
[![Total Downloads](https://img.shields.io/packagist/dt/bingcool/swoolefy.svg)](https://packagist.org/packages/bingcool/swoolefy)

---



## 📑 导航

- [一、📖 简介](#nav-1-intro)
- [🎯 核心特性](#nav-core)
- [🏛️ 架构设计](#nav-arch)
  - [进程模型](#nav-arch-process)
  - [http 请求处理流程](#nav-arch-http)
- [二、📦 版本选择](#nav-2-version)
- [三、⚙️ 实现的功能特性](#nav-3-features)
- [四、🔌 适配协程环境组件](#nav-4-components)
- [五、📚 bingcool/library 组件库](#nav-5-library)
- [六、📥 安装](#nav-6-install)
- [七、📝 添加 cli.php 入口](#nav-7-cli)
- [八、📂 创建 App 项目](#nav-8-create)
- [九、🚀 启动 http 应用](#nav-9-start)
- [十、🌐 访问](#nav-10-access)
- [十一、🧩 定义组件](#nav-11-define)
- [十二、💡 使用组件](#nav-12-use)
- [十三、⚙️ Protocol/conf.php](#nav-13-protocol)
- [十四、🛣️ 路由系统](#nav-14-route)
- [十五、⚡ 协程单例](#nav-15-singleton)
- [十六、⚡ 协程并发](#nav-16-concurrent)
- [十七、🗄️ 数据库操作](#nav-17-db)
- [十八、📦 SDK 自动生成](#nav-18-sdk)
- [十九、📘 ApiDoc 自动生成](#nav-19-apidoc)
- [二十、☁️ Nacos 微服务集成](#nav-20-nacos)
- [二十一、🤖 AI / Workflow 工作流](#nav-21-ai-workflow)
- [二十二、🧠 AI Agent / RAG / MCP / OCR 大模型能力](#nav-22-ai-capabilities)
- [二十三、📬 Job 异步任务](#nav-23-job)
- [🔐 Auth 统一身份](#nav-auth)
- [🌐 I18n 国际化](#nav-i18n)
- [🧪 PHPUnit / PHPUintTest](#nav-phpunit)

---



### 一、📖 简介

**swoolefy** 是基于 [Swoole](https://www.swoole.com/) 的轻量级、高性能、常驻内存协程应用框架，面向 HTTP / WebSocket / UDP / TCP-RPC 与 Worker 多进程消费等场景，支持 Composer 安装与部署。

| 维度 | 能力 |
|------|------|
| **协议与进程** | HTTP API、WebSocket、UDP、可扩展 TCP RPC；Worker 多进程消费模型 |
| **运行时** | Event 事件抽象（与底层回调解耦）、协程单例、同步/异步调用、全局事件、心跳、异步任务、进程池与连接池 |
| **内置组件** | `log` · `session` · `mysql` · `pgsql` · `redis` · `mongodb` · `kafka` · `amqp` · `uuid` · `route` / `middleware` · `cache` · `queue` · `rateLimit` · `traceId` |
| **Auth** | JWT Guard + `FrameworkContext` 协程身份；HTTP / WS / Workflow HITL 共用；`goApp` 可透传（array 快照） |
| **Job** | 统一信封 / Handler / Registry / 重试退避 / Redis 死信；对接现有 Redis·AMQP·Kafka 自定义进程（零建表） |
| **AI 一体化** | Neuron LLM、Workflow DAG、AINode 流式、六种 Agent 路由、RAG（租户隔离）、MCP、**DocumentOcr**（DOCX / 图片 / PDF → Markdown → RAG） |
| **生产级工作流** | 条件边 AI 决策、人机协同 HITL、节点超时、Saga、快照恢复、Provider Fallback |
| **测试** | PHPUnit 11 单轨：`PHPUintTest/`（Unit / Coroutine / Http / Websocket）；`composer test` 默认绿灯 |

实用主义优先：高频能力收敛进框架，编排交给 Workflow / Agent，身份走 Auth，异步走 Job，回归走 PHPUintTest。

#### 模块速览

| 模块 | 一句话 | 文档 |
|------|--------|------|
| **Workflow** | DAG 工作流：条件边、HITL、Saga、RunStore、Plugin | [AI-WORKFLOW](docs/AI-WORKFLOW.md) · [Support/Workflow](src/Support/Workflow/README.md) · [二十一](#nav-21-ai-workflow) |
| **Neuron** | LLM Provider / Fallback / Embedding / ChatHistory / 出站守卫 | [Support/Neuron](src/Support/Neuron/README.md) · [二十二](#nav-22-ai-capabilities) |
| **AI** | `AINode`、Structured Output、SSE·WS 流式、多 Agent 并行节点 | [Support/AI](src/Support/AI/README.md) · [二十二](#nav-22-ai-capabilities) |
| **Agent** | 六种路由 + 协程并行调度 | [Support/Agent](src/Support/Agent/README.md) · [二十二](#nav-22-ai-capabilities) |
| **RAG** | 向量入库/检索、租户隔离、sync·queue Dispatcher | [Support/Rag](src/Support/Rag/README.md) · [二十二](#nav-22-ai-capabilities) |
| **MCP** | HTTP/SSE Tools、多租户配置、stdio 生产禁用 | [Support/Mcp](src/Support/Mcp/README.md) · [二十二](#nav-22-ai-capabilities) |
| **DocumentOcr** | DOCX / 图片 / PDF → Markdown，可接入 RAG | [DocumentOcr](docs/DocumentOcr.md) · [Support/DocumentOcr](src/Support/DocumentOcr/README.md) · [二十二](#nav-22-ai-capabilities) |
| **CapabilityCenter** | 能力/工具注册与发现（Agent / MCP 共用） | [CapabilityTool](docs/CapabilityTool.md) · [Support/CapabilityCenter](src/Support/CapabilityCenter/README.md) |
| **Job** | 轻量异步任务信封与 Runner，不替换进程模型 | [Job](docs/Job.md) · [Support/Job](src/Support/Job/README.md) · [二十三](#nav-23-job) |
| **Auth** | `AuthUser` + JWT Guard；HTTP / WS / HITL 同门面 | [Auth](docs/Auth.md) · [Support/Auth](src/Support/Auth/README.md) · [简介](#nav-auth) |
| **Oauth** | QQ / 微信扫码·公众号·小程序 / 支付宝 / 飞书 / 钉钉 / 企微；DI `oauth` | [Oauth](docs/Oauth.md) · library `Oauth` |
| **I18n** | `LocaleMiddleware` 协商 locale + Symfony `translator` 组件 | [I18n](docs/I18n.md) |
| **Nacos** | 配置监听、服务注册/发现、SDK `base_uri` 解析 | [Support/Nacos](src/Support/Nacos/README.md) · [二十](#nav-20-nacos) |
| **Mqtt** | MQTT 协议服务与优雅停机 | [src/Mqtt](src/Mqtt/README.md) |
| **Websocket** | 推送、离线、Cluster、Socket.IO 等 | [架构/协议](#nav-arch) · 测试见 PHPUintTest |
| **PHPUintTest** | PHPUnit 11 单轨；Unit / Coroutine / Http / Websocket | [PHPUnitTest](docs/PHPUnitTest.md) · [简介](#nav-phpunit) |
| **Health** | K8s `/health`·`/ready` 探针（与 CLI `ProductionHealthCheck` 互补） | [Http/Health](src/Http/Health/README.md) · Config/health.php |
| **library** | 协程组件库（DB / Redis / Queue / Jwt …） | [bingcool/library](https://github.com/bingcool/library) · [五](#nav-5-library) |

### 🎯 核心特性

- ⚡ **高性能**: 基于 Swoole 协程，单机支持数万并发连接
- 🔧 **易扩展**: 自定义进程、进程池、连接池机制
- 🏗️ **多协议**: HTTP/WebSocket/TCP/UDP/MQTT 统一架构
- 🎨 **易用性**: Laravel 风格的路由、中间件、ORM
- 🔄 **热更新**: 文件修改自动重启 Worker，无需停机 (开发环境)
- 👥 **多进程管理**:
  - **守护进程 (Daemon)**: 常驻内存，自动拉起多个 Worker 进程，支持进程健康监控和动态扩缩容
  - **Cron 计划任务**: 类似 Linux crontab，支持 local/fork/url 三种调度模式，定时执行业务逻辑
- ⚛️ **协程并发**:
  - **goApp()**: 一键创建协程单例，自动处理 DB/Redis/Curl 等组件的协程隔离
  - **Parallel**: 限制最大并发数，防止瞬间创建大量协程拖垮下游服务
  - **GoWaitGroup**: 类似 Go 语言的 WaitGroup，优雅的协程同步等待机制
- 📦 **组件化**:
  - **bingcool/library** 大量常用协程组件库 @see [https://github.com/bingcool/library](https://github.com/bingcool/library)
- ☁️ **Nacos 微服务集成**:
  - **配置变更监听**: 长轮询 Nacos 配置，拉取最新内容写入 `APP_PATH/.env`，自动执行 `restart --force` 使 Worker 加载新配置
  - **服务注册**: 应用实例注册到 Nacos 注册中心，支持心跳保活（`application.yaml` → `nacos.service_register`）
  - **服务发现**: `DiscoveryClient` 拉取健康实例，内置 `random` / `round_robin` / `weight` 负载均衡
  - **SDK 服务发现**: `gen:sdk` 生成的 API 客户端在未传入 Guzzle Client 时，自动通过 Nacos 解析目标服务 `base_uri`（`serviceName` 在生成时从 `application.yaml` 注入）
- 🔐 **Auth 统一身份**（`src/Support/Auth/`）:
  - **AuthUser + JwtAuthGuard**: 签发 / 验票同一 Guard；组件名 `auth.guard`
  - **FrameworkContext**: 协程 Context 存 array 快照，`goApp` 可透传；禁止进程级「当前用户」
  - **三通道**: `AuthenticateMiddleware`（HTTP）/ WS 握手 / Workflow HITL `*ForUser`（详见 [docs/Auth.md](docs/Auth.md)）
- 🤖 **AI / LLM / RAG / Agent / OCR**:
  - **LLM（Neuron）**: Provider 工厂、Fallback、Middleware、Embedding、ChatHistory（Redis/SQL）、出站 URL 守卫（`src/Support/Neuron/`）
  - **AI 节点**: `AINode`、Structured Output、SSE/WebSocket 流式输出、多 Agent 并行节点（`src/Support/AI/`）
  - **Agent**: Static / Rule / Weighted / CostAware / RoundRobin / LLM 六种路由 + 协程并行调度（`src/Support/Agent/`）
  - **RAG**: 多向量库入库/检索、租户隔离、sync·queue Dispatcher（`src/Support/Rag/`）
  - **MCP**: HTTP/SSE Tools、DB 多租户配置、stdio 生产禁用（`src/Support/Mcp/`）
  - **DocumentOcr**: Pandoc（DOCX/HTML/MD）+ DeepSeek OCR（图片/PDF）→ Markdown → RAG（`src/Support/DocumentOcr/`，详见 [docs/DocumentOcr.md](docs/DocumentOcr.md)）
  - **Workflow**: DAG 工作流引擎，支持 AI 决策分支、多 Agent 并行、RAG/MCP 节点与人机协同 HITL（详见 [二十一](#nav-21-ai-workflow)、[二十二](#nav-22-ai-capabilities)）
- 📬 **Job 异步任务**（`src/Support/Job/`）:
  - 统一信封 + Handler + Registry + 重试/退避 + Redis 死信重放
  - 对接现有 Redis / AMQP / Kafka 自定义进程，**不新建 SQL 表**、不替换 `ProcessManager`（详见 [二十三](#nav-23-job)、[docs/Job.md](docs/Job.md)）
- 🧪 **PHPUnit / PHPUintTest**:
  - 唯一推荐运行器：PHPUnit 11；用例目录 `PHPUintTest/`（Unit · Coroutine · Http · Websocket）
  - 默认 `composer test` = unit + coroutine；Http / Websocket / Redis 等独立 suite 或 `@group`
  - 方案与命令：[docs/PHPUnitTest.md](docs/PHPUnitTest.md)

<a id="nav-auth"></a>

### 🔐 Auth 统一身份（简介）

HTTP Bearer、WebSocket 握手与 Workflow HITL 共用 `AuthGuardInterface`（默认 `JwtAuthGuard`），身份经 `FrameworkContext::setUser` 写入当前协程（array 快照，可随 `goApp` 透传）。业务只读 `FrameworkContext::user()` / `getUserId()`，禁止把 Body/Query 的 `uid` 当鉴权身份。

| 入口 | 说明 |
|------|------|
| 模块 README | [src/Support/Auth/README.md](src/Support/Auth/README.md) |
| 完整文档 | [docs/Auth.md](docs/Auth.md) |
| 联调 | `GET /api/auth-user/me`（`Test/Controller/AuthUserController`） |
| 测试 | `composer test:auth` |

<a id="nav-i18n"></a>

### 🌐 I18n 国际化（简介）

请求语言由 `LocaleMiddleware` 协商（Query / Header / Accept-Language），写入协程 `lang_locale`；`translator` 组件按该 locale 加载 `Resource/Translations/{locale}/messages.php`。

```php
LocaleMiddleware::apply($requestInput);
$t = Application::getApp()->get('translator');
echo $t->trans('hello');
```

完整说明：[docs/I18n.md](docs/I18n.md)。

<a id="nav-phpunit"></a>

### 🧪 PHPUnit / PHPUintTest（简介）

测试已单轨迁入 **`PHPUintTest/`**（命名空间 `PHPUintTest\`），由 `phpunit.xml.dist` 按 suite 分层；旧 `src/**/Tests` 脚本式回归已删除。

| 命令 | 说明 |
|------|------|
| `composer test` | unit + coroutine（默认 CI 绿灯） |
| `composer test:http` / `test:http:ci` | 真服务 Guzzle 黄金路径（模式 A / B） |
| `composer test:websocket` / `test:mqtt` / `test:support` | 协议与 Support 模块 |
| `composer test:coverage` | 文本覆盖率（无强制门槛） |

详见 [docs/PHPUnitTest.md](docs/PHPUnitTest.md)。

### 🏛️ 架构设计



### 进程模型

```
┌─────────────────────────────────────────────────────┐
│              Master Process (主进程)                 │
│  - 管理 Reactor 线程                                  │
│  - 接收并分发客户端连接                                 │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────┐
│              Master Process (主进程)                     │
│  - 管理 Reactor 线程                                      │
│  - 接收并分发客户端连接                                     │
└──────────────┬──────────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────┐
│              Manager Process (管理进程)                  │
│  - 管理 Worker 进程池                                    │
│  - 管理 Task 进程池                                      │
│  - 管理自定义进程 (通过 addProcess 拉起)                   │
│  - 进程重启和监控                                         │
└──────────┬────────────────────┬─────────────────────────┘
           │                    │
           ├───────────┬────────┴──────────┐
           │           │                    │
    ┌──────▼──────┐ ┌──▼──────────┐ ┌──────▼──────────┐
    │   Worker    │ │    Task     │ │  User Process   │
    │  Processes  │ │  Processes  │ │  (MainProcess)  │
    │  (业务处理)  │ │ (异步任务)   │ │  (管理进程)       │
    │             │ │             │ │                 │
    │ - onRequest │ │ - onTask    │ │ 通过 MainManager │
    │ - onConnect │ │             │ │ 拉起多个 Worker  │
    │ - onReceive │ │             │ │                 │
    │             │ │             │ │ - Cron 任务管理   │
    │ 协程池/组件池 │ │             │ │ - Daemon 常驻     │
    │ - DB 连接池  │ │             │ │ - 动态进程管理    │
    │ - Redis 池  │ │             │ │                  │
    │ - Curl 池   │ │             │ │ run() -> start() │
    └─────────────┘ └─────────────┘ └──────┬──────────┘
                                           │
                          ┌────────────────┼───────────────┐
                          │                │               │
                   ┌──────▼─────┐   ┌──────▼─────┐ ┌──────▼─────┐
                   │   Cron     │   │   Daemon   │ │   Script   │
                   │  Workers   │   │  Workers   │ │  Workers   │
                   │ (定时任务)  │   │ (常驻进程)   │ │ (脚本)      │
                   │            │   │            │ │            │
                   │ - 定时调度  │   │ - 消息消费   │ │ - 临时脚本  │
                   │ - 任务队列  │   │ - 数据处理   │ │ - 数据迁移   │ 
                   │ - URL请求  │   │ - 实时计算   │ │ - 修复工具   │
                   └────────────┘   └────────────┘ └────────────┘
```

**进程层级说明:**

1. **Master Process**: 最高层级，管理 Reactor 线程和连接分发
2. **Manager Process**: 第二层级，统一管理所有子进程
3. **Worker/Task/User Process**: 第三层级，由 Manager 直接管理
4. **Cron/Daemon/Script Workers**: 第四层级，由 User Process (MainProcess) 通过 `MainManager::start()` 拉起



### http请求处理流程

```
Client Request
     ↓
┌────────────────────────┐
│ Swoole HTTP Server     │
│ (Reactor 线程接收)      │
└───────────┬────────────┘
            │
            ↓
┌────────────────────────┐
│ Worker Process         │
│ (onRequest 回调)        │
└───────────┬────────────┘
            │
            ↓
┌────────────────────────┐
│ 1. App::__construct()  │
│    - 加载配置           │
│    - 初始化协程 ID       │
└───────────┬────────────┘
            │
            ↓
┌────────────────────────┐
│ 2. App::run()          │
│    - parseHeaders()    │
│    - initCoreComponent()│
│    - Application::setApp()│ ← 绑定到协程上下文
│    - defer()           │ ← 注册清理钩子
└───────────┬────────────┘
            │
            ↓
┌────────────────────────┐
│ 3. HttpRoute::dispatch()│
│    - 加载路由配置         │
│    - 匹配路由            │
└───────────┬──────────── ┘
            │
            ↓
┌────────────────────────────────┐
│ 4. 执行中间件 (Middleware)       │
│    - beforeHandle (前置中间件)   │
│    - 验证/鉴权/CORS 等           │
│    - 请求参数处理                │
└───────────┬────────────────────┘
            │
            ↓
┌────────────────────────────────┐
│ 5. 调用控制器 Action             │
│    - Controller::action()      │
│    - 业务逻辑处理                │
└───────────┬────────────────────┘
            │
            ↓
┌─────────────────────────────────────┐
│ 6. 执行业务 (Business Logic)         │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ goApp(function() {          │   │
│  │     // 协程并发处理           │   │
│  │     - DB 查询                │   │
│  │     - Redis 操作             │   │
│  │     - HTTP 请求              │   │
│  │     - 文件 IO                │   │
│  │ })                          │   │
│  │                             │   │
│  │ Parallel::run(50, $list,    │   │
│  │     function($item) {       │   │
│  │         // 限制并发数处理     │   │
│  │     }                       │   │
│  │ )                           │   │
│  └─────────────────────────────┘   │
│                                    │
│  - 协程调度器自动切换                 │
│  - IO 密集型任务异步执行              │
│  - CPU 继续执行其他协程               │
└───────────┬────────────────────────┘
            │
            ↓
┌────────────────────────┐
│ 7. 后置中间件            │
│    - afterHandle       │
│    - 响应格式化          │
│    - 日志记录            │
└───────────┬────────────┘
            │
            ↓
┌────────────────────────┐
│ 8. App::end()          │
│    - handleLog()       │
│    - pushComponentPools()│ ← 归还连接池
│    - clearComponent()  │
│    - response->end()   │
└───────────┬────────────┘
            │
            ↓
Client Response
```

---



### 二、📦 版本选择



#### 6.x 版本 (推荐 - 最新稳定版)

**最低要求:**

- PHP >= 8.4
- Swoole >= 6.1 (推荐使用 Swoole 6.x 最新版本)



#### 4.9 LTS 版本 (长期维护版)

**最低要求:**

- PHP 7.3 ~ 7.4
- Swoole 4.8.x (推荐 4.8.13+)

**选择哪个版本?**  
1、如果确定项目是使用php81+的，那么直接选择 `swoole > 6.1.x，推荐直接使用 swoole-6.2.0+ 以上最新版本更好` 安装，然后选择 `bingcool/swoolefy:^6.1` 作为项目分支安装最新稳定版本   

2、如果确定项目是使用 `php7.3 ~ php7.4` 的，那么选择 swoole-v4.8+ 版本来进行编译安装(不能直接使用 swoole-cli-v4.8+ 了, 因为其内置的是php8.1，与你的项目的php7不符合)
所有只能通过编译swoole源码的方式来生成swoole扩展，然后选择 `bingcool/swoolefy:^4.9` 作为项目分支稳定版本   

3、依赖编译： ./configure --enable-openssl --enable-sockets --enable-swoole-curl --enable-swoole-pgsql --enable-swoole-stdext --enable-iouring     

4、若不希望自己编译构建，也可以直接使用本目录下的Dockerfile来构建镜像:     

```
// 构建镜像
docker build --no-cache -t swoolefy-php84-swoole62:v1 -f ./php84-swoole62-io-uring.Dockerfile .   

// 启动容器(开发环境下 --security-opt seccomp=unconfined的作用是禁用这个默认配置，让容器内的进程可以使用所有系统调用比如io_uring)   
// 生产环境下建议使用配置文件方式 --security-opt seccomp=./seccomp_profile.json     
// @see https://github.com/moby/moby/blob/v28.3.3/profiles/seccomp/default.json      
docker run -d -it --security-opt seccomp=unconfined -p 9501:9501 -p 9502:9502 -v /host_mnt/Users/macbook/Documents/wwwphp:/home/wwwroot --name=swoolefy-php84-v62 swoolefy-php84-swoole62:v1

```



### 三、⚙️ 实现的功能特性



#### 基础特性

- [x] 支持架手脚一键创建项目自动生成最小项目骨架         
- [x] 支持 `gen:apidoc` 按模块扫描 Router 生成 OpenAPI 3.0 文档（Swagger UI 浏览）
- [x] 支持分组路由, 路由中间件middleware, 前置路由组件, 后置路由组件middleware,多模块应用     
- [x] 支持扫描Router路由配置自动生成PHP SDK，自动提取 Request/Response DTO，生成类型安全的客户端SDK代码    
- [x] 支持自定义注册不同根命名空间，快速多项目部署          
- [x] 支持httpServer，实用轻量Api接口开发     
- [x] 支持多协议websocketServer、udpServer、mqttServer      
- [x] 支持基于tcp实现的rpc服务，开放式的系统接口，可自定义协议数据格式，并提供rpc-client协程组件
- [x] 支持DI容器，组件IOC、配置化，Channel公共组件池            
- [x] 支持协程单例注册,协程上下文变量寄存    
- [x] 支持mysql、postgreSql、redis协程组件   
- [x] 支持全局logger组件，包括system log, runtime log,  request log, sql log     
- [x] 支持opentelemetry的trace链路追踪组件        
- [x] 支持分布式锁组件       
- [x] 支持滑动窗口的流量速率组件        
- [x] 支持mysql协程连接池
- [x] 支持redis协程池   
- [x] 支持curl协程池   
- [x] 支持protobuf buffer的数据接口结构验证，压缩传输等        
- [x] 支持异步务管理TaskManager  
- [x] 定时器管理TickManager  
- [x] 内存表管理TableManager  
- [x] 支持自定义进程管理ProcessManager，进程池管理PoolsManger
- [x] 支持底层异常错误的所有日志捕捉,支持全局日志,包括debug、info、notice、warning、error等级       
- [x] 支持自定义进程的redis，rabbitmq，kafka的订阅发布，消息队列等
- [x] **Job 轻量异步任务**（`Swoolefy\Support\Job`，详见 [docs/Job.md](docs/Job.md)、[src/Support/Job/README.md](src/Support/Job/README.md)、[二十三](#nav-23-job)）：统一信封 / Handler / Registry / Runner（重试退避）/ Redis 死信重放，零建表，不替换 ProcessManager  
- [x] 支持热更新reload worker 监控以及更新                 
- [x] 支持定时的系统信息采集，并以订阅发布，udp等方式收集至存贮端    
- [x] 支持命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架, 并对外提供控制启动|停止|重启|查看状态的api接口，可开发成可视化控制页面    



##### 高级特性

- [x] 支持cron计划任务模式. 类似crontab，支持local|fork|remote url三种方式      

  | 支持方式  | 说明                                                    |
  | ----- | ----------------------------------------------------- |
  | local | 自定义进程内定时执行代码                                          |
  | fork  | 自定义进程定时拉起一个新的进程，由新的进程去执行任务，可异步，类似laravel的schedule计划任务 |
  | url   | 自定义进程定时发起远程url请求，可设置callback回调处理结果                    |


- [x] 支持daemon模式.worker下后台daemon模式的多进程协程消费模型,包括进程自动拉起，进程数动态调整，进程健康状态监控     
- [x] 支持console终端脚本模式. 跑完脚本自动退出，可用于修复数据、数据迁移等临时脚本功能      
- [x] **Nacos 配置中心与服务治理**（`Swoolefy\Support\Nacos`，详见 [src/Support/Nacos/README.md](src/Support/Nacos/README.md)）
    创建应用（`php cli.php create App`）时会自动生成 `APP_PATH/application.yaml` 模板；Nacos 连接信息放在 `APP_PATH/nacos.yaml`。

  | 文件                          | 说明                                                                                              |
  | --------------------------- | ----------------------------------------------------------------------------------------------- |
  | `APP_PATH/nacos.yaml`       | Nacos **服务器连接**（host、port、username/password 等）                                                  |
  | `APP_PATH/application.yaml` | **应用行为**：`service_config`、`service_register`、`discovery_service_client`、`monitor_config_change` |


  | 能力       | 说明                                                       | 主要类                                                                                        |
  | -------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
  | 配置变更监听   | 长轮询配置 → 写入 `.env` → 后台 `cli.php restart {App} --force=1` | `NacosMonitor`、`ConfigWatcher`（见 [Monitor/README.md](src/Support/Nacos/Monitor/README.md)） |
  | 服务注册     | 注册实例到 Nacos 并定时心跳                                        | `ServiceRegister`                                                                          |
  | 服务发现     | 实例列表缓存 + 负载均衡选节点                                         | `DiscoveryClient`、`DiscoveryConfig`、`LoadBalancerFactory`                                  |
  | SDK 服务发现 | `gen:sdk` 生成客户端，未传 Guzzle 时自动 Nacos 发现 `base_uri`        | `BaseClientApi`、`SdkNacosServiceDiscovery`（sdk自动生成）                                        |

    自定义进程示例（`Event.php` 中注册）：
    服务发现代码示例：

- [x] **AI Agent / RAG / MCP / OCR / Workflow / LLM 大模型能力**（`Swoolefy\Support`，详见 [二十一](#nav-21-ai-workflow)、[二十二](#nav-22-ai-capabilities)）
    基于 [Neuron AI](https://docs.neuron-ai.dev/) + Swoolefy 协程运行时，提供可编排的工作流与可独立使用的大模型原语。

  | 能力               | 说明                                                                | 主要路径                            |
  | ---------------- | ----------------------------------------------------------------- | ------------------------------- |
  | Workflow         | DAG 引擎、条件边、HITL、Saga、Plugin、多版本 Registry                          | `src/Support/Workflow/`         |
  | Neuron / LLM     | Provider 工厂、Fallback、Middleware、Embedding、ChatHistory、出站 URL 守卫   | `src/Support/Neuron/`           |
  | AI 节点            | AINode、Structured Output、SSE/WS 流式、多 Agent 并行节点                   | `src/Support/AI/`               |
  | Agent            | Static / Rule / Weighted / CostAware / RoundRobin / LLM 路由 + 协程调度 | `src/Support/Agent/`            |
  | RAG              | 多向量库入库/检索、租户隔离、sync·queue Dispatcher                              | `src/Support/Rag/`              |
  | MCP              | HTTP/SSE Tools、DB 多租户、stdio 生产禁用                                  | `src/Support/Mcp/`              |
  | DocumentOcr      | Pandoc + DeepSeek OCR（图片/PDF）→ Markdown → RAG                     | `src/Support/DocumentOcr/`      |
  | CapabilityCenter | 百级 Tool Top-K + pinned（可选）                                        | `src/Support/CapabilityCenter/` |




### 四、🔌 适配协程环境组件


| 组件名称                 | 安装                                                         | 说明                                                  |
| -------------------- | ---------------------------------------------------------- | --------------------------------------------------- |
| predis               | composer require predis/predis:~3.4.0                      | predis组件、或者Phpredis扩展                               |
| mongodb              | composer require mongodb/mongodb:~1.3                      | mongodb组件，需要使用mongodb必须安装此组件                        |
| rpc-client           | composer require bingcool/rpc-client:dev-master            | swoolefy的rpc客户端组件，当与rpc服务端通信时，需要安装此组件，支持在php-fpm中使用 |
| cron-expression      | composer require dragonmantank/cron-expression:~3.3.0      | crontab计划任务组件，类似Linux的crobtab                       |
| redis lock           | composer require malkusch/lock                             | Redis锁组件                                            |
| amqp                 | composer require php-amqplib/php-amqplib:~3.7.0            | amqp php原生实现amqp协议客户端                               |
| ffmpeg               | composer require php-ffmpeg/php-ffmpeg:~1.4.0              | php proc-open 调用ffmpeg处理音视频                         |
| image                | composer require intervention/image:~3.11.0                | php 图像处理组件                                          |
| validate             | composer require vlucas/valitron                           | validate数据校验组件                                      |
| guzzlehttp           | composer require guzzlehttp/guzzle:~7.9.0                  | guzzlehttp 组件                                       |
| oauth 2.0            | composer require league/oauth2-server                      | oauth 2.0 授权认证组件                                    |
| php-standard-library | composer require php-standard-library/php-standard-library | php标准库(推荐)                                          |
| bingcool/library     | composer require bingcool/library                          | library组件库                                          |
| neuron-ai            | composer require neuron-core/neuron-ai                     | Neuron AI 大模型 Agent / RAG / MCP 原语（框架已集成）           |




### 五、📚 bingcool/library 是 swoolefy require 内置库，专为 swoole 协程实现的组件库

实现了包括：    

- [x] Db ORM Model 组件(支持mysql、 postSql、 sqlite、 Oracle)
- [x] DB Query Builder 链式操作查询组件      
- [x] Kafka Producer Consumer组件
- [x] Rabbitmq Queue组件  
- [x] Rabbitmq Delay Queue 死信延迟队列组件    
- [x] Redis Cache组件  
- [x] Redis Queue队列组件   
- [x] Redis Delay Queue延迟队列组件            
- [x] RedisLock锁组件   
- [x] RateLimit限流组件   
- [x] Redis Public Subscribe组件    
- [x] Db、Redis、 Curl协程连接池组件
- [x] UUid 分布式自增id组件  
- [x] OpenTelemetry 链路追踪组件      
- [x] nacos 服务注册、服务发现、服务配置 SDK（底层 Client；应用集成见 Support/Nacos）
- [x] Curl基础组件    
- [x] Jwt 组件   
- [x] Validate 组件    
- [x] Encrypt 加密解密组件   
- [x] Captcha 验证码组件    

> I18n（请求语言 + Symfony Translation）在 **swoolefy 应用层**：`LocaleMiddleware` + `translator` 组件，见 [docs/I18n.md](docs/I18n.md)。library 不含独立 Translation 组件。

github: [https://github.com/bingcool/library](https://github.com/bingcool/library)    

### 六、📥 安装



#### 1、先配置环境变量(必须设置)

```
// 独立物理机或者云主机配置系统环境变量
vi /etc/profile

在/etc/profile末尾添加一行，标识环境变量，下面是支持的4个环境,框架将通过这个环境变量区分环境，加载不同的配置

export SWOOLEFY_CLI_ENV='dev'  // 开发环境
export SWOOLEFY_CLI_ENV='test' // 测试环境
export SWOOLEFY_CLI_ENV='gra'  // 灰度环境
export SWOOLEFY_CLI_ENV='prd'  // 生产环境

// 最后是配置生效
source /etc/profile

```

```
// 如果是通过dockerfile 创建容器的, 可以根据不同环境生成的内置环境变量不同镜像，每个不同的环境镜像可以用在不同环境，代码将通过这个环境变量区分环境，加载不同的配置
ENV SWOOLEFY_CLI_ENV=dev

```



#### 2、创建项目

```
// 下载代码到到你的自定义目录，这里定义为myproject, 新建composer.json

{
  "name": "project/order-service",
  "description": "description",
  "minimum-stability": "dev",
  "prefer-stable": true,
  "license": "proprietary",
  "require": {
    "bingcool/swoolefy": "~6.3",
    "bingcool/library": "^6.0"
  }
}
  
// 终端执行安装
composer install

```



### 七、📝 添加项目入口启动文件 `cli.php`, 并定义你的项目目录，命名为 `App`

```php
<?php
// 在myproject目录下添加cli.php, 这个是启动项目的入口文件

date_default_timezone_set('Asia/Shanghai');
include __DIR__.'/vendor/autoload.php';

$appName = ucfirst($_SERVER['argv'][2]);
// 定义app name
define('APP_NAME', $appName);
// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);
// composer安装时，必须定义成如下路径
defined('SRC_DIR_ROOT') or define('SRC_DIR_ROOT', __DIR__."/vendor/bingcool/swoolefy/src");
// 应用父目录
defined('ROOT_PATH') or define('ROOT_PATH',__DIR__);
// 应用目录
defined('APP_PATH') or define('APP_PATH',__DIR__.'/'.$appName);

registerNamespace(APP_PATH);

// 你的项目命名为App，对应协议为http协议服务器，支持多个项目的，只需要在这里添加好项目名称与对应的协议即可
define('APP_META_ARR', [
    'Test' => [
        'protocol' => 'http',
        'worker_port' => 9501,
    ],
    'App' => [
        'protocol' => 'http',
        'worker_port' => 9502,
    ]
]);
// 定义服务端口
define('WORKER_PORT', APP_META_ARR[$appName]['worker_port']);
define('IS_WORKER_SERVICE', 0);
define('IS_DAEMON_SERVICE', 0);
define('IS_SCRIPT_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('PHP_BIN_FILE','/usr/bin/php');

define('WORKER_START_SCRIPT_FILE', str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD']) ? $_SERVER['SCRIPT_FILENAME'] : $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_SERVICE_NAME', makeServerName($appName));
define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/'.WORKER_SERVICE_NAME);
define('WORKER_CTL_LOG_FILE',WORKER_PID_FILE_ROOT.'/ctl.log'); 
define('CLI_TO_WORKER_PIPE', WORKER_PID_FILE_ROOT.'/cli.pipe');
define('WORKER_TO_CLI_PIPE', WORKER_PID_FILE_ROOT.'/ctl.pipe');
define('SERVER_START_LOG_JSON_FILE', WORKER_PID_FILE_ROOT.'/start.json');

// nacos.yaml 完整路径（环境变量 NACOS_FILE_PATH 可覆盖，默认 APP_PATH/nacos.yaml）
$nacosFilePath = getenv('NACOS_FILE_PATH');
define('NACOS_FILE_PATH', (false !== $nacosFilePath && '' !== $nacosFilePath) ? $nacosFilePath : APP_PATH . '/nacos.yaml');

// 当使用nacos管理配置时，启动获取最新配置保存到.env
// $beforeFunc = function () {
//    \Swoolefy\Support\Nacos\NacosFactory::fetchConfigToEnv();
//};

include dirname(SRC_DIR_ROOT).'/swoolefy';


```



### 八、📂 执行创建你定义的 App 项目

```
// 你定义的项目目录是App, 在myproject目录下执行下面命令行

php cli.php create App   
或者  
swoole-cli cli.php create App 


// 执行完上面命令行后，将会自动生成 App 项目目录以及内部子目录
myproject
|—— App           // 应用项目目录
|     |── Config       // 应用配置
|     |   |__ component  // 协程单例组件
|     |      |—— database.php  // 数据库相关组件
|     |      |—— log.php       // 日志相关组件
|     |      |—— cache.php     // 缓存组件，可以继续添加其他组件，命名自由
|     │   ├── dc.php           // 环境配置项
|     │   └── constants.php
|     |   |—— app.php          // 应用层配置
|     |
|     ├── Controller
|     │   └── IndexController.php  // 控制器层
|     ├── Model
|     │   └── ClientModel.php      // 数据模型层
|     ├── Module        // 模块层
|     ├── Protocol      // 协议配置
|     │   ├── conf.php  // 全局配置
|     │
|     ├── Router
|     │   └── api.php   // 路由文件，不同模块定义不同文件即可
|     |—— Storage
|     |   |—— Crontab   // cron service 的调度日志
|     |   |—— Logs      // 日志文件目录
|     |   |—— Sql       // sql 日志目录
|     |—— Scripts
|     |   |—— Kernel.php    // 计划任务定义
|     |__ .env              // 自动生成环境变量文件
|     │—— Autoloader.php    // 自定义项目自动加载
|     |—— Event.php         // 事件实现类
|     |—— HttpServer.php    // http server
|    
|——— cli.php        // http应用启动入口文件
|——— cron.php       // 定时 worker 任务的多进程启动入口文件
|——— daemon.php     // 守护进程 worker 的多进程启动入口文件
|——— script.php     // 脚本启动入口文件

```



### 九、🚀 启动 http应用项目

**http应用启动命令行**

```
// 终端启动 ctl+c 停止进程
php cli.php start App
或者    
swoole-cli cli.php start App

// 守护进程方式启动,添加-D参数控制
php cli.php start App --daemon=1
或者  
swooole-cli cli.php start App --daemon=1

// 停止进程 
php cli.php stop App

或者   
swooole-cli cli.php stop App --force=1

// 查看进程状态
swooole-cli cli.php status App

// 完全重启服务
php cli.php restart App    
或者    
swooole-cli cli.php restart App

```

**启动Cron定时计划任务服务**

```
// 创建生成Cron定时计划任务服务,默认生成WorkerCron目录

php script.php start App --c=gen:cron:service

// 启动Cron服务, CTRL+C 停止进程

php cron.php start App

// --daemon=1 以守护进程启动

php cron.php start App --daemon=1

// 重启Cron服务

php cron.php restart App



// 停止Cron服务，终端交互询问需要输入`yes` or `no` 再次确认是否需要停止服务

php cron.php stop App

// --force=1 强制停止Cron服务，不询问直接停止服务

php cron.php stop App --force=1

```

**启动Daemon常驻进程服务**

```
// 创建生成Daemon常驻进程消费服务,默认生成WorkerDaemon目录

php script.php start App --c=gen:daemon:service

// 启动Daemon服务，CTRL+C 停止进程

php daemon.php start App 

// --daemon=1 以守护进程启动

php daemon.php start App --daemon=1

// 重启Daemon服务

php daemon.php restart App




// 停止Daemon服务, 终端交互询问是否需要输入`yes` or `no` 再次确认是否需要停止服务

php daemon.php stop App

// --force=1 强制停止Daemon服务，不询问直接停止服务

php daemon.php stop App --force=1


```



### 十、🌐 访问

默认端口是9502,可以通过 [http://localhost:9502](http://localhost:9502) 访问默认控制器

```php
<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

// 默认生成的IndexController
class IndexController extends BController {

    public function index() {
        // 最简单的协程单例，goApp()即可创建一个协程,在单例中的db,redis等其他注册的组件都是单例的，不同协程单例相互隔离  
        goApp(function() {
            var_dump('this is a coroutine single app test');
        });
        
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }
}

```

至此一个最简单的http的服务就创建完成了，更多例子请参考项目下Test的demo

### 十一、🧩 定义组件

1、应用层配置文件：Config/app.php

```php
<?php
return [

    // db|redis连接池
    'component_pools' => [
        // 取components的`DB`组件名称相对应
        'db' => [
            'max_pool_num' => 5, // db实例数
            'max_push_timeout' => 2, // db实例进入channel池最长等待时间，单位s
            'max_pop_timeout' => 1, // db实例出channel池最长等待时间，单位s.在规定时间内获取不到db对象，将降级为实时创建db实例
            'max_life_timeout' => 10, // db实例的有效期，单位s.过期后将被掉弃，重新创建新DB实例
            'enable_tick_clear_pool' => 0 // 是否每分钟定时清空pool，防止长时间一直占用链接，max_pool_num设置很大的时候需要设置，否则不需要设置
        ],
    
        // 取components的`redis`组件名称相对应
        'redis' => [
            'max_pool_num' => 5,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 10,
            'enable_tick_clear_pool' => 0 // 是否每分钟定时清空pool，防止长时间一直占用链接，max_pool_num设置很大的时候需要设置，否则不需要设置
        ]
    ],
    
     // default_db
    'default_db' => 'db',

    // 加载组件配置
    'components' => \Swoolefy\Core\SystemEnv::loadComponent()
    
    // 其他配置
    ......
]

```

2、组件Component.php

```php
<?php

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 用户行为记录的日志
    'log' => function($name) {
        $logger = new Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/info.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/info.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron/info.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/info.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 用户行为记录错误日志
    'error_log' => function($name) {
        $logger = new Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/error.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron/error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 系统捕捉抛出异常错误日志
    'system_error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(SystemEnv::isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon/system_error.log';
        }else if (SystemEnv::isScriptService()) {
            $logFilePath = LOG_PATH.'/script/system_error.log';
        }else if (SystemEnv::isCronService()) {
            $logFilePath = LOG_PATH.'/cron/system_error.log';
        } else {
            $logFilePath = LOG_PATH.'/cli/system_error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    }
    
    // Redis Cache
    'redis' => function() use($dc) {
        $redis = new \Swoolefy\Library\Redis\Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        return $redis;
    },
    
    // Predis Cache
    'predis' => function() use($dc) {
        $predis = new \Swoolefy\Library\Redis\predis([
            'scheme' => $dc['predis']['scheme'],
            'host'   => $dc['predis']['host'],
            'port'   => $dc['predis']['port'],
        ]);
        return $predis;
    }
    
```



### 十二、💡 使用组件

```php
use Swoolefy\Core\Application;

class TestController extends BController {
    /**
    * 控制器
    */
    public function test() {
        // 获取组件，组件就是配置回调中定义的组件
        $redis = Application::getApp()->redis;
        //或者通过get指明组件名获取(推荐)
        // $redis = Application::getApp()->get('redis');

        // swoole hook 特性，这个过程会发生协程调度
        $redis->set('name', swoolefy);

        // predis组件
        $predis = Application::getApp()->predis;
        //或者通过get指明组件名获取(推荐)
        // $predis = Application::getApp()->get('predis');
        
        // 这个过程会发生协程调度
        $predis->set('predis','this is a predis instance');
        $predis->get('predis');
        
        // PDO的mysql实例，这个过程会发生协程调度
        $db = Application::getApp()->db;
        // 或者
        // $mysql = Application::getApp()->get('db');
        // 添加一条数据
        $sql = "INSERT INTO `user` (`username` ,`sex`) VALUES (:username, :sex)"; 
        $numRows = $db->createCommand($sql)->insert([
            ':username'=>'bingcool-test',
            ':sex' => 1
        ]);
        var_dump($numRows)
        
        // DB Query查询
         $db = Application::getApp()->db;
         $db->newQuery()->table('user')->where([
            'user_id' => 10000
         ])->select()
         
         // DB 插入单条数据
         $data = [
            'username'=>'bingcool-test',
            'sex' => 1
         ]
         $db = Application::getApp()->db;
         $db->newQuery()->table('user')->insert($data);
         
         // DB 插入多条数据
         $data = 
         [
            [
                'username'=>'bingcool-test1111',
                'sex' => 1
            ],
            [
                'username'=>'bingcool-test2222',
                'sex' => 1
            ]
         ]
         $db = Application::getApp()->db;
         $db->newQuery()->table('user')->insertAll($data);
         
         
        // 查询
        $result = $db->createCommand('select * from user where id>:id')->queryOne([':id'=>100]);
        var_dump($result);    

        // pg实例    
        $pg = Application::getApp()->get('pg');   
        // 添加一条数据   
        $sql = "INSERT INTO `user` (username ,sex) VALUES (:username, :sex)"; 
        $pg->createCommand($sql)->insert([
            ':username'=>'bingcool-test',
            ':sex' => 1
        ]);
    }
}

```



### 十三、⚙️ 默认协议层全局配置文件 Protocol/conf.php

*配置项*

开发者可以根据实际使用适当调整

```php
$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 应用层配置
    'app_conf'                 => \Swoolefy\Core\SystemEnv::loadAppConf(), // 应用层配置
    'application_index'        => '',
    'event_handler'            => \Test\Event::class,
    'exception_handler'        => \Test\Exception\ExceptionHandle::class,
    'response_formatter'       => \Swoolefy\Core\ResponseFormatter::class,
    'master_process_name'      => 'php-swoolefy-http-master',
    'manager_process_name'     => 'php-swoolefy-http-manager',
    'worker_process_name'      => 'php-swoolefy-http-worker',
    'www_user'                 => '',
    'host'                     => '0.0.0.0',
    'port'                     => '9501',
    'time_zone'                => 'PRC',
    'swoole_process_mode'      => SWOOLE_PROCESS,
    'include_files'            => [],
    'runtime_enable_coroutine' => true,

    // swoole setting
	'setting' => [
        'admin_server'           => '0.0.0.0:9503',
        'reactor_num'            => 1,
        'worker_num'             => 4,
        'max_request'            => 10000,
        'task_worker_num'        => 2,
        'task_tmpdir'            => '/dev/shm',
        'daemonize'              => 0,
        'dispatch_mode'          => 3,
        'reload_async'           => true,
        'enable_coroutine'       => 1,
        'task_enable_coroutine'  => 1,
        // 压缩
        'http_compression'       => true,
        // $level 压缩等级，范围是 1-9，等级越高压缩后的尺寸越小，但 CPU 消耗更多。默认为 1, 最高为 9
        'http_compression_level' => 1,
        'log_file'               => \Swoolefy\Core\SystemEnv::loadLogFile('/tmp/' . APP_NAME . '/swoole_log.txt'),
        'pid_file'               => \Swoolefy\Core\SystemEnv::loadPidFile('/data/' . APP_NAME . '/log/server.pid'),
	],

    'coroutine_setting' => [
        'max_coroutine' => 50000
    ],

    // 是否内存化线上实时任务
    'enable_table_tick_task' => true,

    // 内存表定义
    'table' => [
        'table_process' => [
             // 内存表建立的行数,取决于建立的process进程数,最小值64
             'size' => 64,
              // 定义字段
              'fields'=> [
                     ['pid','int', 10],
                     ['process_name','string', 56],
                  ]
               ]
     ],

    // 依赖于EnableSysCollector = true，否则设置没有意义,不生效
    'enable_pv_collector'  => false,
    'enable_sys_collector' => true,
    'sys_collector_conf' => [
        'type'           => SWOOLEFY_SYS_COLLECTOR_UDP,
        'host'           => '127.0.0.1',
        'port'           => 9504,
        'from_service'   => 'http-app',
        'target_service' => 'collectorService/system',
        'event'          => 'collect',
        'tick_time'      => 2,
        'callback'       => function () {
            $sysCollector = new \Swoolefy\Core\SysCollector\SysCollector();
            return $sysCollector->test();
        }
    ],

    // 热更新
    'reload_conf'=> [
        'enable_reload'     => false, // 是否启用热文件更新功能       
        'after_seconds'     => 3, // 检测到只要有文件更新，3s内不在检测，等待重启既可     
        'monitor_path'      => APP_PATH, // 开发者自己定义目录
        'reload_file_types' => ['.php', '.html', '.js'],
        'ignore_dirs'       => [],
        'callback'          => function () {}
    ]
];

```



### 十四、🛣️ 路由系统

支持类似 Laravel 的分组路由和中间件:

*Router/api.php*

```php

<?php
use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

// 直接路由-不分组
Route::get('/index/index', [
    'beforeHandle' => function(RequestInput $requestInput) {
        Context::set('name', 'bingcool');
        $name = $requestInput->getPostParams('name');
    },

    // 这里需要替换长对应的控制器命名空间
    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

    'afterHandle' => function(RequestInput $requestInput) {

    },
    'afterHandle1' => function(RequestInput $requestInput) {

    },
]);

// 分组路由
Route::group([
    // 路由前缀
    'prefix' => 'api',
], function () {

    // 公开接口：不要整组挂鉴权，否则健康检查 / 登录也会 401
    Route::get('/index/index', [
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
    ]);

    // 需登录：挂框架 AuthenticateMiddleware（需 Config/auth.php + component/auth.php）
    Route::get('/me', [
        'beforeHandle' => \Swoolefy\Http\Middleware\AuthenticateMiddleware::class,
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
    ]);
});

```



### 十五、⚡ 协程单例

*协程单例*  

```php

// 协程单例使用goApp直接调用创建, 每个协程的DB，redis,kafka,mq的socket对象相互隔离，互不影响，代码通用
goApp(function() {
    $db = Application::getApp()->get('db');
    // 查询列表
    $db->newQuery()->table('tbl_users')->where('id','>', 1)->field(['id', 'user_name'])->limit(0,10)->select();
    // redis
    $redis = Application::getApp()->get('redis');
    $redis->set('name','bingcool');
   
    // 再开启一个协程单例
    goApp(function() {
        // $db1与父级协程的$db完全隔离，不是同一个对象
        $db1 = Application::getApp()->get('db');
    })
})

```

*协程隔离示意图:*

```php
    协程 A (cid=1001)              协程 B (cid=1002)
    ↓                              ↓
    App Instance A                App Instance B
    ↓                              ↓
    containers['db'] A         containers['db'] B
    ↓                              ↓
    Redis Object A                Redis Object B
    (独立 Socket 连接)              (独立 Socket 连接)

```



### 十六、⚡ 协程并发



#### Parallel 并发限制器

```php
use Swoolefy\Core\Coroutine\Parallel;

// 场景：有 1000 个请求，限制每次并发 50 个
$parallel = new Parallel(50);

for ($i = 0; $i < 1000; $i++) {
    $parallel->add(function() use ($i) {
        // 协程任务
        $result = file_get_contents("http://api.example.com/data?id={$i}");
        return json_decode($result, true);
    }, "key_{$i}");
}

// 长等待10s获取结果
$results = $parallel->runWait(10.0);


// 场景：少量的请求，通过add添加闭包
$parallel = new Parallel();
$parallel->add(function() {
    return file_get_contents("http://api.example.com/data");
}, "key1");

$parallel->add(function() {
    return file_get_contents("http://api.example.com/data");
}, "key2");

$parallel->add(function() {
    return file_get_contents("http://api.example.com/data");
}, "key3");

// 最长等待10s获取结果
$parallel->runWait(10.0)


```



#### Parallel::run 迭代并发

```php
use Swoolefy\Core\Coroutine\Parallel;

// 分批处理大数据集(无需等待数据返回)
$list = range(1, 10000);

Parallel::run(
    100,           // 每批 100 个协程
    $list,         // 数据数组
    function($item) {
        // 处理每个元素
        echo "Processing: {$item}\n";
    },
    0.01          // 每批间隔 0.01 秒
);
```



#### GoWaitGroup

```php
use Swoolefy\Core\Coroutine\GoWaitGroup;

$wg = new GoWaitGroup();

for ($i = 0; $i < 10; $i++) {
    $wg->add();
    goApp(function() use ($wg, $i) {
        try {
            // 并发任务
            sleep(1);
            echo "Task {$i} done\n";
        } finally {
            $wg->done();
        }
    });
}

$wg->wait();  // 等待所有任务完成
```



### 十七、🗄️ 数据库操作

```php

$db = Application::getApp()->get('db');
// 插入单条数据
$db->newQuery()->table('tbl_users')->insert([
            'user_name' => '李四-'.rand(1,9999),
            'sex' => 0,
            'birthday' => '1991-07-08',
            'phone' => 12345678
    ]);

// 批量插入
$db->newQuery()->table('tbl_users')->insertAll([
            [
                'user_name' => '李四-'.rand(1,9999),
                'sex' => 0,
                'birthday' => '1991-07-08',
                'phone' => 12345678
            ],
            [
                'user_name' => '李四-'.rand(1,9999),
                'sex' => 0,
                'birthday' => '1991-07-08',
                'phone' => 12345678
            ]
    ]);


// 查询列表
$db->newQuery()->table('tbl_users')->where('id','>', 1)->field(['id', 'user_name'])->limit(0,10)->select();

// 查询单条
$db->newQuery()->table('tbl_users')->where(['id', '=', 100])->field(['id', 'user_name'])->find();

.....还有很多其他链式操作

```



### 十八、📦 SDK 自动生成

swoolefy 提供了 **SDK 自动生成工具**，可以扫描项目的 Route 路由配置，自动提取 API 接口信息和 Request/Response DTO，生成类型安全的 PHP 客户端 SDK 代码。

#### 核心特性

- 🔍 **自动扫描路由**: 解析 `App/Router` 目录下的所有路由配置文件
- 📝 **提取 DTO**: 自动识别控制器方法中的 Request 和 Response 类型声明
- 🎯 **类型安全**: 生成的 SDK 包含完整的类型声明，IDE 智能提示友好
- 🔄 **自动更新**: 路由变更后重新生成即可，无需手动维护
- ☁️ **Nacos 服务发现**: 生成时通过 `NacosServiceRegisterConfig` 注入 `BaseClientApi::$serviceName`；构造 API 客户端时未传入 `ClientInterface` 则委托框架 `DiscoveryClient` 自动解析 `base_uri`（需 `NACOS_FILE_PATH` / `APP_PATH` 下存在 `nacos.yaml` 与 `application.yaml`）



#### 使用方法

```bash
# 基本用法：扫描默认 App/Router 目录，生成到 GenerateSdk 目录
php script.php start App --c=gen:sdk

# 指定路由目录
php script.php start App --c=gen:sdk --router=App/Router

# 指定输出目录 ProjectName 是具体项目名 OrderService
php script.php start App --c=gen:sdk --out=../sdk-library/{ProjectName}
```



#### 生成的 SDK 结构

```
GenerateSdk/
├── {ProjectName}/
│   └── {AppName}/
│       ├── Support/              # SDK 基础支撑类
│       │   ├── BaseClientApi.php           # HTTP 客户端基类（可选 Nacos 发现）
│       │   ├── SdkNacosServiceDiscovery.php  # 委托 DiscoveryClient 解析 base_uri
│       │   ├── SdkArrayDto.php
│       │   ├── SdkCovertProperty.php
│       │   └── ...
│       ├── Controller/
│       │   └── Client/
│       │       ├── IndexApi.php      # 对应 IndexController
│       │       ├── UserApi.php       # 对应 UserController
│       │       └── OrderApi.php      # 对应 OrderController
│       ├── Request/              # Request DTO
│       │   └── UserLoginRequest.php
│       └── Response/             # Response DTO
│           └── UserListResponse.php
```



#### 示例代码

**使用生成的 API 客户端（固定地址 / 自定义 Client）：**

```php
use GenerateSdk\MyProject\App\Controller\Client\UserApi;
use GenerateSdk\MyProject\App\Request\UserLoginRequest;

// 指定固定 base_uri（不走 Nacos 服务发现）
$api = UserApi::makeService(null, 'http://127.0.0.1:9501');

// 自定义 Guzzle Client（完全手动）
$client = new \GuzzleHttp\Client(['base_uri' => 'http://api.example.com/']);
$api = new UserApi($client);

// 使用 Request DTO 调用
$request = new UserLoginRequest();
$request->setUsername('admin');
$request->setPassword('123456');
/** @var UserLoginResponse $response */
$response = $api->login($request);

var_dump($response->getToken());
var_dump($response->getUserId());
```

**Nacos 服务发现 SDK 详细用法：**

生成 SDK 时会从 `application.yaml` → `nacos.service_register.service_name` 注入 `BaseClientApi::$serviceName`。  
调用 `makeService()` 且**不传** `$httpClient` / `$baseUri` 时，自动通过 Nacos 发现可用实例并设置 Guzzle `base_uri`。

**前置条件**


| 项    | 说明                                                                                           |
| ---- | -------------------------------------------------------------------------------------------- |
| 依赖   | SDK 包需 `composer require bingcool/swoolefy`（`SdkNacosServiceDiscovery` 委托 `DiscoveryClient`） |
| 配置文件 | 配置目录下需存在 `nacos.yaml`（Nacos 连接）与 `application.yaml`（`discovery_service_client` 等）            |
| 配置路径 | `NACOS_FILE_PATH`（nacos.yaml）+ `APP_PATH`（application.yaml）                                  |


```php
use GenerateSdk\MyProject\Order\Client\OrderApi;
use GenerateSdk\MyProject\Order\Request\CreateOrderRequest;

// ① Nacos 服务发现（推荐）
// serviceName 已在 gen:sdk 时注入，无需手写 base_uri
$orderApi = OrderApi::makeService();

// ② GET / PUT / DELETE 等幂等请求：Connect/Request 异常默认重试 1 次（最大可通过 options 调到 3）
$orderListReq = new OrderListRequest();
$orderListReq->setName('手机');
$orderListReq->setPage(1);
$orderListReq->setSize(20);
// 可选，
$options = [
     // 可选，可设置 headers、connect_retry_num、timeout 等 Guzzle 选项
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'X-Request-Id'  => uniqid('req_', true),
    ],
    'connect_retry_num' => 2, // 可选，0~3；不传则 GET 默认 1
];

$list = $orderApi->list($orderListReq, $options);


// ③ POST 写操作：默认不重试（保证幂等由业务决定），需显式开启
$createReq = new CreateOrderRequest();
$createReq->setProductId(1001);
$createReq->setQuantity(2);

$result = $orderApi->create($createReq, [
    // POST 默认 connect_retry_num=0；仅当业务确认接口幂等时才设置connect_retry_num时开启重试机制
    'connect_retry_num' => 1,
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Idempotency-Key' => 'order-create-' . $createReq->getProductId(), //建议配合幂等键
    ],
]);

// ④ 固定 base_uri（不走 Nacos，指定GuzzleHttp\Client对象，失败时退避 200ms / 500ms / 1s 后重试同一地址）
$client = new \GuzzleHttp\Client([
    'base_uri' => 'http://api.example.com/',
]);
$orderApi = OrderApi::makeService($client);
// ⑤ GET / PUT / DELETE 等幂等请求
$orderDetailReq = new OrderDetailRequest();
$orderListReq->setOrderId(1001);
$detail = $orderApi->detail($orderDetailReq);

```

**重试与日志说明**


| 场景                          | 行为                                                                             |
| --------------------------- | ------------------------------------------------------------------------------ |
| Nacos 发现 + 重试               | `RequestException` 时重新 `choose` 节点后立即重试，**无退避**                                |
| 固定地址 + 重试                   | 同一 `base_uri` 退避 **200ms → 500ms → 1s** 后重试                                    |
| GET/HEAD/PUT/DELETE/OPTIONS | 默认重试 **1** 次                                                                   |
| POST/PATCH 等                | 默认 **0** 次，须 `$options['connect_retry_num']` 显式指定                              |
| 重试上限                        | `connect_retry_num` 最大 **3**                                                   |
| 日志                          | 重试时写入 guzzle_curl 日志（`CurlProxyHandler::buildLogChannel()`），含失败/下一跳 IP:端口及异常信息 |


> `$options['connect_retry_num']` 为 SDK 专用参数，不会传给 Guzzle；自定义请求头通过 `$options['headers']` 传入，会与默认 `Content-Type: application/json` 合并。

**控制器返回值类型声明最佳实践：**

为了让 SDK 生成更准确，建议在控制器 action 方法中添加返回值类型声明：

```php
<?php
namespace App\Controller;

use Swoolefy\Core\Controller\BController;
use App\Request\UserLoginRequest;
use App\Response\UserLoginResponse;

class UserController extends BController
{
    // ✅ 有返回对象时声明具体类型
    public function login(UserLoginRequest $request): UserLoginResponse
    {
        return $response;
    }
    
    // ✅ 无返回值时使用 bool
    public function delete(int $id): bool
    {
        return true;
    }
    
    // ✅ 返回数组时使用 array
    public function list(): array
    {
        return ['total' => 100, 'list' => []];
    }
}
```



#### 工作原理

1. **扫描路由文件**: 解析 `App/Router/*.php` 中的所有路由定义
2. **反射分析**: 通过 PHP Reflection 分析控制器方法的参数和返回类型
3. **提取 DTO**: 识别 `Test\`* 命名空间下的 Request/Response 类
4. **生成代码**:
  - 复制 DTO 类到 SDK 目录，移除框架依赖
  - 生成 API 客户端类，每个控制器对应一个 `*Api.php` 文件
  - 生成 Guzzle HTTP 客户端调用代码
5. **类型转换**: 自动处理 JSON 响应到 DTO 对象的转换



#### 注意事项

- 控制器方法建议使用类型声明（Request/Response 类或标量类型）
- DTO 类应位于 `App/Request`、`App/Response` 或 `App/Dto` 目录下
- 生成的 SDK 可在 PHP-FPM 或 CLI 中使用；启用 Nacos 服务发现时需依赖 `bingcool/swoolefy`（`SdkNacosServiceDiscovery` 委托 `DiscoveryClient`）
- SDK 基于 Guzzle HTTP 客户端，需要安装 `guzzlehttp/guzzle` 依赖
- Nacos 发现配置：`NACOS_FILE_PATH` 指定 nacos.yaml，`APP_PATH` 指定 application.yaml



### 十九、📘 ApiDoc 自动生成

swoolefy 提供了 **ApiDoc 自动生成工具**（`gen:apidoc`），扫描 Route 的 `dispatch_route`，结合 Request/Response DTO 与注解生成 **OpenAPI 3.0** YAML。生成结果可放到 `swaggerui/apidoc/`，用内置 Swagger UI 浏览。

> 详细说明见 **[src/Script/ApiDoc/README.md](src/Script/ApiDoc/README.md)**。

#### 核心特性

- 🔍 **按模块生成文档**: 扫描 `App/Router`，输出 `openapi-{module}.yaml`
- 📝 **接口文案瀑布**: `#[ApiOperation]` → PHPDoc 首行 → `{METHOD} {path}` → action 名（不再把 summary 盲目复制到 description）
- 📤 **GET/HEAD/DELETE**: `BaseRequest` 展开为 **query parameters**；POST/PUT/PATCH 仍为 **requestBody**
- 📦 **请求/响应结构**: 反射 DTO 属性、嵌套对象、`ValidationRule` 必填与数组 item
- ⚠️ **默认 responses**: 始终含 `200` + `400`；路由含鉴权/限流中间件时补充 `401` / `429`
- 🏷️ **tags**: 路由文件名；若有 `@api` 注释则为 `api内容(文件名)`

#### 使用方法

```bash
php script.php start App --c=gen:apidoc
php script.php start App --c=gen:apidoc --router=App/Router
php script.php start App --c=gen:apidoc --router=App/Router/Order --out=swaggerui/apidoc
```

#### 模块与全局配置

1. **模块级**（优先）：`Router/api_router_module.json` 的 `title` / `description`
2. **全局兜底**（可选）：`Config/apidoc.php`（模版 `src/Stubs/apidoc.conf.stub.php`）
3. **默认**：`title` = `{App} · {Module}`，`description` = `Generated by swoolefy gen:apidoc`

```json
{
  "Product": {
    "title": "用户产品中心",
    "description": "用户产品模块"
  }
}
```

#### 注解示例

```php
use Swoolefy\Annotation\ApiOperation;

class UserController extends BController
{
    // 仅 description → 用作 summary（不再重复写到 description）
    #[ApiOperation(description: '创建用户')]
    public function create(UserCreateRequest $request): UserCreateResponse
    {
        // ...
    }

    // summary + description 分开
    #[ApiOperation(summary: '查询用户', description: '需登录')]
    public function show(UserShowRequest $request): UserShowResponse
    {
        // ...
    }
}
```

```php
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class UserCreateRequest extends BaseRequest
{
    #[ApiProperty(description: "Username")]
    #[ValidationRule(rule: "required|string", message: "username is required")]
    protected string $username = "";

    #[ApiProperty(description: "Role list")]
    #[ValidationRule(rule: "required|array", itemClass: RoleDto::class)]
    protected array $roles = [];
}

// Controller 内可读：$request->validated() / only() / getUsername()
```

#### 注意事项

- action 建议显式声明 Request / Response 类型；入参继承 `BaseRequest`（自带 `validated()` / `only()` 等助手）
- 字段建议加 `#[ApiProperty]`；数组对象用 `ValidationRule(itemClass: ...)` 或 `#[ArrayList]`
- GET 查询复杂嵌套对象时，query 表达能力有限，宜拆标量或改用 POST body
- 每次生成会清理输出目录下旧的 `openapi-*.yaml`



### 二十、☁️ Nacos 微服务集成

框架内置 Nacos **配置监听**、**服务注册**、**服务发现**，并与 `gen:sdk` 生成的 HTTP 客户端打通。实现位于 `src/Support/Nacos/`，应用侧参考 `Test/nacos.yaml`、`Test/application.yaml` 与 `Test/Process/NacosProcess/`。

#### 配置文件


| 文件                          | 内容                                                                                                        |
| --------------------------- | --------------------------------------------------------------------------------------------------------- |
| `APP_PATH/nacos.yaml`       | Nacos 服务器连接（host、port、鉴权等）                                                                                |
| `APP_PATH/application.yaml` | `nacos.service_config`（配置中心 dataId）、`service_register`、`discovery_service_client`、`monitor_config_change` |




#### 环境变量

各环境变量与 YAML 键的对应关系、默认值及说明见 **[src/Support/Nacos/README.md#env-vars](src/Support/Nacos/README.md#env-vars)**。

常用变量速查：


| 分类   | 环境变量                                                                 | 说明                      |
| ---- | -------------------------------------------------------------------- | ----------------------- |
| 全局   | `NACOS_FILE_PATH`                                                    | `nacos.yaml` 路径         |
| 连接   | `NACOS_HOST`、`NACOS_PORT`、`NACOS_USERNAME`、`NACOS_PASSWORD`          | Nacos 服务器连接             |
| 配置中心 | `application.yaml` → `nacos.service_config.data_id` / `group`        | 项目配置 dataId（必填，不支持环境变量） |
| 注册   | `NACOS_SERVICE_REGISTER_HOST`、`POD_IP`、`NACOS_SERVICE_REGISTER_PORT` | 本实例注册信息                 |


`application.yaml` 片段示例：

```yaml
nacos:
  enable_nacos_register: true
  service_config:
    data_id: my-project.env
    group: DEFAULT_GROUP
    tenant: ''
  service_register:
    # 注册 IP 读取顺序：NACOS_SERVICE_REGISTER_HOST（本地开发）→ POD_IP（K8s/ACK）→ YAML ip → 自动探测
    ip: 192.168.1.102
    # 优先读取 NACOS_SERVICE_REGISTER_PORT 环境变量。一般不需要配置，除非docker映射端口不一致。默认读取cli.php环境变量WORKER_PORT
    # port: 9501
    service_name: my-service
    heartbeat_interval: 10   # 心跳间隔（秒）
    namespace_id: 'production'
    group_name: 'pwa_group'
    weight: 1
    ephemeral: true
    # 非空时注册到 Nacos 的 metadata 参数会自动转为 JSON 字符串
    metadata:
      version: 1.0.0
  discovery_service_client:
    load_balancer: random   # random | round_robin | weight
    cache_ttl: 60
    healthy_only: true
    namespace_id: 'production'
    group_name: 'pwa_group'
  monitor_config_change:
    listener_timeout_ms: 30000
```



#### 开发环境

本地与 dev 共用同一份 `.env`（数据库等配置相同），连接同一套 Nacos；区别在于**注册 IP** 与**分组**：本机服务注册到个人调试分组，dev 已部署服务注册在 `application.yaml` 中配置的 `group_name`（如 `frame_group`）。

在本地开发环境设置环境变量，方便本地各个服务注册到个人分组与调试，互不影响：

```bash
export NACOS_SERVICE_REGISTER_HOST="127.0.0.1"
export NACOS_SERVICE_GROUP_NAME="bingcool"
export LOCAL_NACOS_SERVICE_AUTO_SWITCH=1
export INNER_EXTERNAL_BASE_URI="http://product-service-dev.example.com:19000"
```


| 环境变量                              | 说明                                                                                                                                                        |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `NACOS_SERVICE_REGISTER_HOST`     | 本机注册到 Nacos 的 IP。本地开发设为 `127.0.0.1`，避免把内网 IP 注册出去                                                                                                         |
| `NACOS_SERVICE_GROUP_NAME`        | 本机注册与发现使用的分组（如个人名 `bingcool`），与 dev 环境部署的分组隔离，互不影响                                                                                                        |
| `LOCAL_NACOS_SERVICE_AUTO_SWITCH` | 设为 `1` 时，SDK 调用依赖服务：先在当前分组（如 `bingcool`）查找实例；若无可用实例，自动回退到 `application.yaml` → `nacos.service_register.group_name`（frame_group 分组）中已部署的服务，便于本地只启动部分服务即可联调 |
| `INNER_EXTERNAL_BASE_URI`         | 内部跨环境可访问的服务 URL。服务注册时会写入 Nacos metadata 的 `inner_external_base_uri`；SDK 仅在本地自动切 dev 分组时优先用该 URL，避免本地直接访问 K8s Pod IP                                       |


典型场景：本地启动 `order-service` 开发订单功能，需调用 `product-service`；本地未启动 `product-service` 时，SDK 会自动切到 dev 开发环境中已部署的 `product-service` 实例。**注意：本地网络需能访问 dev 部署实例注册的 IP。**

#### ACK / Kubernetes 部署

在 ACK/Kubernetes 中建议通过 Downward API 注入 Pod IP，框架注册 IP 读取顺序为：`NACOS_SERVICE_REGISTER_HOST`（本地开发显式覆盖）→ `POD_IP`（K8s/ACK）→ `application.yaml` 的 `nacos.service_register.ip` → 自动探测。

Deployment 片段示例：

```yaml
env:
  - name: POD_IP
    valueFrom:
      fieldRef:
        fieldPath: status.podIP
```



#### 配置变更监听（自动重启）

1. 长轮询 Nacos 配置变更
2. 拉取最新配置写入 `APP_PATH/.env`
3. 随机短暂延迟后执行 `php cli.php restart {APP_NAME} --force=1`，Worker 加载新环境变量

在 `Event.php` 注册自定义进程 `NacosConfigReload`，或调用 `NacosMonitor::run()`。详见 [src/Support/Nacos/Monitor/README.md](src/Support/Nacos/Monitor/README.md)。

#### 服务注册

`ServiceRegister` 使用 `NacosConfig`（`nacos.yaml` 连接）+ `NacosServiceRegisterConfig`（`application.yaml` → `nacos.service_register`），将当前实例注册到 Nacos 并定时心跳。

在 `application.yaml` 设置 `enable_nacos_register: true` 时，框架会自动启动内置进程 `NacosRegisterServiceProcess`（无需在 `Event.php` 手动注册）。也可在自定义进程中调用：

```php
use Swoolefy\Support\Nacos\NacosConfig;
use Swoolefy\Support\Nacos\ServiceRegister;

$register = ServiceRegister::create();
$register->register();
```



#### 服务发现

`DiscoveryClient` 读取 `discovery_service_client` 配置，拉取实例列表（支持缓存 TTL），通过负载均衡器选择节点：

```php
use Swoolefy\Support\Nacos\Discovery\DiscoveryClient;

$client = DiscoveryClient::create('my-service'); // 也可传 NacosConfig / DiscoveryConfig
$instance = $client->choose();
$uri = $client->chooseUri();
$metadata = $instance?->getMetadata() ?? [];
```



#### 与 gen:sdk 的关系


| 步骤  | 行为                                                                                                                                       |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| 生成时 | 通过 `NacosServiceRegisterConfig` 读取 `service_register.service_name`，写入 `BaseClientApi::$serviceName`                                      |
| 运行时 | `UserApi::make()` 未传 Guzzle Client → `SdkNacosServiceDiscovery` → `DiscoveryClient::choose()` → 设置 Guzzle `base_uri`，并保存选中实例的 `metadata` |


SDK 客户端可读取当前选中实例 metadata：

```php
$api = UserApi::makeService();
$metadata = $api->getNacosInstanceMetadata();
$version = $api->getNacosInstanceMetadataValue('version', 0);
```

SDK 调用下游服务时会自动透传入口请求中的白名单 Header，并兼容框架已有协程级 `x-trace-id`：


| Header         | 说明                             |
| -------------- | ------------------------------ |
| `x-trace-id`   | 链路追踪 ID，优先使用协程上下文中的 trace id   |
| `x-user-id`    | 登录用户 ID                        |
| `x-user-code`  | 登录用户编码                         |
| `x-tenant-id`  | 租户 ID                          |
| `x-user-name`  | 用户名                            |
| `x-client-ip`  | 客户端 IP                         |
| `x-user-agent` | SDK 下游请求固定为 `swoolefy-api-sdk` |


业务显式传入 `$options['headers']` 时优先级最高，可覆盖自动透传值。

业务代码可通过 `FrameworkContext` 读取当前请求上下文：

```php
use Swoolefy\Support\FrameworkContext;

$userId = FrameworkContext::getUserId();
$tenantId = FrameworkContext::getTenantId();
$userAgent = FrameworkContext::getUserAgent();
$userCode = FrameworkContext::get('x-user-code');
```

```bash
php script.php start App --c=gen:sdk --router=App/Router --out=../generate-sdk-library/OrderService
```

更多 API 说明见 [src/Support/Nacos/README.md](src/Support/Nacos/README.md)。

### 二十一、🤖 AI / Workflow 工作流

框架内置 **DAG 工作流引擎** + **Neuron AI** 集成，支持 AI 决策分支、多 Agent 并行、RAG 知识库、MCP 工具调用与人机协同（HITL）。已实现 **Phase 1–4** 及 **生产加固（Phase A/B/P0）**：HITL API 鉴权、status 脱敏、resume CAS、多版本 Registry、Embedding fail-fast、MCP 租户 DB、RAG 显式 tenantId、启动期 `ProductionHealthCheck` 等。


| 文档                                                               | 说明                                |
| ---------------------------------------------------------------- | --------------------------------- |
| [docs/AI-WORKFLOW.md](docs/AI-WORKFLOW.md)                       | **快速接入**：配置、HTTP API、示例 curl、测试命令 |
| [src/Support/Workflow/README.md](src/Support/Workflow/README.md) | 引擎原理、条件边、HITL、Saga、Plugin         |
| [SwoolefyAI.md](docs/SwoolefyAI.md)                              | 完整架构设计与 Phase 路线图                 |




#### 核心模块


| 模块       | 路径                      | 能力                                                       |
| -------- | ----------------------- | -------------------------------------------------------- |
| Workflow | `src/Support/Workflow/` | Definition / Compiler / Engine、HITL 鉴权、多版本 Registry、Saga |
| AI       | `src/Support/AI/`       | AINode、流式 SSE/WebSocket、StructuredOutput、节点超时            |
| Agent    | `src/Support/Agent/`    | Static / Rule / LLM / CostAware / RoundRobin 路由          |
| Neuron   | `src/Support/Neuron/`   | LLM 工厂、Redis/SQL 记忆、Embedding fail-fast、URL 校验           |
| RAG      | `src/Support/Rag/`      | 向量库、同步/队列入库 Dispatcher、显式 tenantId、别名 fail-fast          |
| MCP      | `src/Support/Mcp/`      | HTTP/SSE MCP、DB 多租户、stdio 生产禁用                           |




#### 配置与装配

```bash
# 创建应用时 create 命令会自动复制；也可手动从 Stubs 复制
cp src/Stubs/workflow.conf.stub.php App/Config/workflow.php
cp src/Stubs/neuron_ai.conf.stub.php App/Config/neuron_ai.php
```

生产环境推荐 `WorkflowComponentFactory` + `WorkflowRegistry`（支持 Redis RunStore 跨 Worker resume）：

```php
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;

$registry = new WorkflowRegistry();
$registry->register('order_processing', fn () => OrderProcessingWorkflow::definition());

$compiler = WorkflowComponentFactory::compiler();
$engine = WorkflowComponentFactory::engine($registry);
$compiled = $compiler->compile($registry->definition('order_processing'));
$runId = $engine->start($compiled, ['orderId' => 10001]);
```



#### HTTP API（Test 演示）

启动 Test 应用后（默认端口 9501）：

```bash
# 启动工作流
curl -X POST http://127.0.0.1:9501/api/v1/workflow/run \
  -H 'Content-Type: application/json' \
  -d '{"workflowId":"order_processing","input":{"orderId":10001}}'

# Agent 对话
curl -X POST http://127.0.0.1:9501/api/v1/agent/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"hello","sessionId":"s1","userId":"u1"}'
```


| 接口                                 | 说明                                                       |
| ---------------------------------- | -------------------------------------------------------- |
| `POST /api/v1/workflow/run`        | 启动工作流                                                    |
| `GET /api/v1/workflow/run/status`  | 查询 Run 状态（HITL 鉴权；默认脱敏摘要，`admin + detail=true` 返回完整调试视图） |
| `POST /api/v1/workflow/run/resume` | HITL 恢复（须 `X-Workflow-Api-Key` 或角色，见 Workflow README）    |
| `POST /api/v1/workflow/run/cancel` | 取消 Run（HITL 鉴权）                                          |
| `GET /api/v1/workflow/pause/tasks` | 待审批任务（HITL 鉴权）                                           |
| `GET /api/v1/workflow/run/events`  | SSE 流式事件                                                 |
| `POST /api/v1/agent/chat`          | Agent 对话                                                 |
| `GET /api/v1/mcp/servers`          | MCP 服务列表（`?tenantId=`）                                   |
| `GET /api/v1/mcp/servers/tools`    | MCP 工具发现（`?server_id=&tenantId=`）                        |


示例工作流：`order_processing`、`order_saga`、`multi_agent_research`、`contract_review`、`knowledge_qa`、`mcp_research`（见 `Test/Module/`）。

#### RAG 入库 CLI

```bash
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --path=/data/docs
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --text="产品规格..."
```



#### 回归测试

```bash
composer test:workflow
```

覆盖 Phase 1–4 共 30+ 用例，含 SubWorkflow、JsonLogic、RoundRobin、RAG CLI 集成测试。

大模型原语（Agent / RAG / MCP / OCR）详见 [二十二、AI Agent / RAG / MCP / OCR 大模型能力](#nav-22-ai-capabilities)。

### 二十二、🧠 AI Agent / RAG / MCP / OCR 大模型能力

在 Workflow 编排之外，框架提供可独立使用的 **大模型能力层**：LLM 装配、多 Agent 路由、RAG 检索增强、MCP 工具协议、文档 OCR，以及可选的 CapabilityCenter 工具筛选。底层复用 [Neuron AI](https://docs.neuron-ai.dev/)，运行时由 Swoolefy 协程 / 组件容器承载。


| 文档                                               | 说明                                                                             |
| ------------------------------------------------ | ------------------------------------------------------------------------------ |
| [docs/SwoolefyAI.md](docs/SwoolefyAI.md)         | 完整架构与模块边界                                                                      |
| [docs/AI-WORKFLOW.md](docs/AI-WORKFLOW.md)       | 生产接入快速指南                                                                       |
| [docs/DocumentOcr.md](docs/DocumentOcr.md)       | DocumentOcr 技术方案                                                               |
| [docs/CapabilityTool.md](docs/CapabilityTool.md) | CapabilityCenter 设计                                                            |
| 各模块 README                                       | `src/Support/{Neuron,AI,Agent,Rag,Mcp,DocumentOcr,CapabilityCenter,Auth,Job}/README.md` |




#### 能力总览


| 能力                   | 路径                              | 要点                                                                         |
| -------------------- | ------------------------------- | -------------------------------------------------------------------------- |
| **Neuron（LLM）**      | `src/Support/Neuron/`           | Provider 工厂、Fallback、Middleware、Embedding、ChatHistory（Redis/SQL）、出站 URL 守卫 |
| **AI 节点**            | `src/Support/AI/`               | `AINode` / StructuredOutput / SSE·WS 流式 / `AgentParallelNode`              |
| **Agent 路由**         | `src/Support/Agent/`            | Static / Rule / Weighted / CostAware / RoundRobin / LLM 六种 Router + 协程并行调度 |
| **RAG**              | `src/Support/Rag/`              | 入库 / 检索 / 多向量库 / 租户隔离 / sync·queue Dispatcher                              |
| **MCP**              | `src/Support/Mcp/`              | HTTP/SSE Tools、DB 多租户配置、stdio 生产禁用、进程并发守卫                                  |
| **DocumentOcr**      | `src/Support/DocumentOcr/`      | Pandoc（DOCX/HTML/MD）+ DeepSeek OCR（图片/PDF）→ Markdown → RAG                 |
| **CapabilityCenter** | `src/Support/CapabilityCenter/` | 百级 Tool 场景 Top-K + pinned（默认关闭，按需启用）                                       |




#### 配置

```bash
# create 应用时会自动复制；也可手动从 Stubs 复制
cp src/Stubs/neuron_ai.conf.stub.php App/Config/neuron_ai.php
cp src/Stubs/document_ocr.conf.stub.php App/Config/document_ocr.php
# 组件：document_parser.php → DI 名 document_ocr
cp src/Stubs/document_parser.component.stub.php App/Config/component/document_parser.php
```

`neuron_ai.php` 覆盖：`ai_model_providers`、`neuron.default_provider` / `provider_fallback`、`rag.*`、`mcp.*`、`security.outbound_url_allowlist`。

#### Neuron：装配 Agent

```php
use Swoolefy\Support\Neuron\NeuronFactory;

$factory = new NeuronFactory();
$agent = $factory->create(MyAgent::class, $state, [
    'provider' => 'openai',           // ai_model_providers 别名
    'mcpServers' => ['docs'],         // 挂载 MCP Tools
    'middleware' => [/* Neuron Middleware */],
]);
```

- 会话记忆在业务 `Agent::chatHistory()` 中声明（`ChatHistoryFactory::redis()` / `sql()` / `inMemory()`）
- Provider 缺凭证 / 未知别名 fail-fast；`baseUri` 经 `OutboundUrlGuard`
- 演示：`POST /api/v1/agent/chat`、`POST /api/v1/agent/middleware/chat`



#### Agent：多 Agent 并行路由

```php
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;

$scheduler = new AgentScheduler(new NeuronFactory());
$results = $scheduler->runParallel($ctx, [
    'weather' => fn ($ctx, $f) => /* ... */,
    'route'   => fn ($ctx, $f) => /* ... */,
], new StaticRouter(['weather', 'route']));
```

工作流内用 `Definition::addAgentParallel()` / `AgentParallelNode`；Router 选出的 id 必须落在 tasks 中，否则 fail-fast（禁止空跑 SUCCESS）。

#### RAG：入库与检索


| 向量库驱动                                  | 说明     |
| -------------------------------------- | ------ |
| `file` / `phpvector`                   | 本地开发   |
| `meilisearch` / `mariadb` / `pgvector` | 自建     |
| `pinecone` / `qdrant` / `milvus`       | 托管 / 云 |


```bash
# 离线入库 CLI
php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --path=/data/docs
```

开启 `RAG_REQUIRE_TENANT_ISOLATION` 时物理名为 `{tenantId}_{kb}`。未知 vector store **alias / driver** 均 fail-fast。

#### MCP：外部 Tools

```php
use Swoolefy\Support\Mcp\McpComponentFactory;

$tools = McpComponentFactory::factory()->tools(['docs']);
```

生产优先远程 HTTP/SSE；stdio 默认禁用（`MCP_ALLOW_STDIO=1` 可开）。DB 仓储见 `Schema/mcp_server_configs.sql`。


| 接口                              | 说明                            |
| ------------------------------- | ----------------------------- |
| `GET /api/v1/mcp/servers`       | 服务列表（凭证脱敏，`?tenantId=`）       |
| `GET /api/v1/mcp/servers/tools` | 工具发现（`?server_id=&tenantId=`） |




#### DocumentOcr：文档 → Markdown

```php
/** @var \Swoolefy\Support\DocumentOcr\DocumentOcrFactory $ocr */
$ocr = Application::getApp()->get('document_ocr');
$result = $ocr->parseFile('/data/manual.docx'); // → $result->markdown
```


| 输入                                 | 驱动                          |
| ---------------------------------- | --------------------------- |
| `.docx` / `.html` / `.md` / `.txt` | Pandoc                      |
| `.png` / `.jpg` / `.jpeg`          | DeepSeek OCR `/api/ocr`     |
| `.pdf`                             | DeepSeek OCR `/api/ocr/pdf` |


再经 `ChunkingAdapter` 接入 `IngestionPipeline` 即可入库。

#### 回归测试

```bash
composer test:support          # 全量 Support（含下列子集）
composer test:neuron
composer test:agent
composer test:rag
composer test:mcp
composer test:document-ocr
composer test:capability
```

生产启动前可用 `ProductionHealthCheck` 校验 Provider / Embedding / 出站 URL / HITL / RunStore 等（见 [二十一](#nav-21-ai-workflow) 与 `docs/AI-WORKFLOW.md`）。

K8s 运行期探针：`GET /health`（liveness）、`GET /ready`（readiness，可配 Redis/DB）；路由 `HealthRoutes::register()`，配置 `Config/health.php`（见 `src/Http/Health/`）。

### 二十三、📬 Job 异步任务

在**现有自定义进程消费**（Redis / AMQP / Kafka）之上提供统一 Job 信封、Handler、Registry 与重试/退避，**默认不新建 SQL 表**，不替换 `ProcessManager` / `Event.php` 进程模型。


| 文档                                                         | 说明                                      |
| ---------------------------------------------------------- | --------------------------------------- |
| [docs/Job.md](docs/Job.md)                                 | 技术方案、语义约定、Phase 路线图                     |
| [src/Support/Job/README.md](src/Support/Job/README.md)     | 快速接入：Publisher / Registry / 死信重放 / 测试命令 |


```bash
composer test:job
```

配置模版：`create` 时复制 `src/Stubs/job.conf.stub.php` → `Config/job.php`；Demo 进程见 `Test/Process/JobProcess/`（在 `Test/Event.php` 中注释注册）。

### License

MIT  
Copyright (c) 2017-2026 zengbing huang    