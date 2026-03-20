# Swoolefy - 基于 Swoole 的高性能协程应用框架

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

## 📖 简介

**Swoolefy** 是一个基于 Swoole 扩展的轻量级、高性能、常驻内存型的**协程级应用服务框架**。采用高度模块化设计，支持 HTTP API、WebSocket、UDP、TCP(RPC)、MQTT 多协议服务器，提供完整的协程单例调度、同步/异步调用、全局事件注册、连接池、进程池等企业级功能。

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

---

## 📦 版本选择

### 6.x 版本 (推荐 - 最新稳定版)

**最低要求:**
- PHP >= 8.2
- Swoole >= 6.0 (推荐使用 Swoole 6.x 最新版本)

**安装命令:**
```bash
composer require bingcool/swoolefy:^6.0
```

### 4.9 LTS 版本 (长期维护版)

**最低要求:**
- PHP 7.3 ~ 7.4
- Swoole 4.8.x (推荐 4.8.13+)

**安装命令:**
```bash
composer require bingcool/swoolefy:^4.9
```

### 如何选择版本？

| 场景 | 推荐方案 |
|------|----------|
| 新项目或 PHP 8.1+ | 使用 **6.x** 版本 + Swoole 6.x |
| 老项目 PHP 7.x | 使用 **4.9 LTS** 版本 + Swoole 4.8.x |
| 需要 io_uring 特性 | 使用 **6.x** + Swoole 6.1+ |

---

## 🚀 快速开始

### 1. 环境准备

#### 方式一：直接安装 Swoole 扩展

```bash
# 下载源码
wget https://github.com/swoole/swoole-src/archive/refs/tags/v6.1.0.tar.gz
tar -zxvf v6.1.0.tar.gz
cd swoole-src-6.1.0

# 编译安装
phpize
./configure --enable-openssl --enable-sockets --enable-swoole-curl --enable-swoole-pgsql --enable-io-uring
make && make install

# 添加扩展到 php.ini
echo "extension=swoole" >> /etc/php.ini
```

#### 方式二：使用 Docker (推荐开发环境)

```bash
# 构建镜像
docker build --no-cache -t swoolefy-php83-swoole61:v1 -f ./dockerfiles/php83-swoole61.Dockerfile .

# 启动容器
# 开发环境 (禁用 seccomp 以支持所有系统调用如 io_uring)
docker run -d -it --security-opt seccomp=unconfined --name=swoolefy-php83-v6 swoolefy-php83-swoole61:v1

# 生产环境 (使用安全配置文件)
docker run -d -it --security-opt seccomp=./dockerfiles/seccomp_profile.json --name=swoolefy-php83-v6 swoolefy-php83-swoole61:v1
```

### 2. 创建项目

```bash
# 创建新项目
composer create-project bingcool/swoolefy:^6.0 myproject

# 进入项目目录
cd myproject
```

### 3. 配置环境变量

编辑项目根目录的 `.env` 文件:

```bash
# 运行环境 (dev/test/gra/prd)
SWOOLEFY_CLI_ENV=dev

# 应用名称
APP_NAME=Test

# 数据库配置
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=test_db
DB_USERNAME=root
DB_PASSWORD=secret

# Redis 配置
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

### 4. 创建应用入口

在 `myproject` 目录下创建 `cli.php`:

```php
<?php
// cli.php - 应用启动入口

date_default_timezone_set('Asia/Shanghai');
include __DIR__.'/vendor/autoload.php';

// 获取应用名称
$appName = ucfirst($_SERVER['argv'][2] ?? 'Test');

// 定义常量
define('APP_NAME', $appName);
define('START_DIR_ROOT', __DIR__);
define('ROOT_PATH', __DIR__);
define('APP_PATH', __DIR__.'/'.$appName);

// 注册命名空间
registerNamespace(APP_PATH);

// 定义应用元数据
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

// 设置当前服务端口
define('WORKER_PORT', APP_META_ARR[$appName]['worker_port']);
define('IS_WORKER_SERVICE', 0);
define('IS_DAEMON_SERVICE', 0);
define('IS_SCRIPT_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('PHP_BIN_FILE', '/usr/bin/php');

// 定义脚本和日志路径
define('WORKER_START_SCRIPT_FILE', str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD']) 
    ? $_SERVER['SCRIPT_FILENAME'] 
    : $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_SERVICE_NAME', makeServerName($appName));
define('WORKER_PID_FILE_ROOT', '/tmp/workerfy/log/'.WORKER_SERVICE_NAME);
define('WORKER_CTL_LOG_FILE', WORKER_PID_FILE_ROOT.'/ctl.log'); 
define('SERVER_START_LOG_JSON_FILE', WORKER_PID_FILE_ROOT.'/start.json');

// 加载框架
include __DIR__.'/swoolefy';
```

### 5. 生成项目骨架

```bash
# 自动生成 Test 应用目录结构
php cli.php create Test
```

执行后将生成以下目录结构:

```
myproject/
├── Test/                          # 应用项目目录
│   ├── Config/                    # 应用配置
│   │   ├── component/             # 协程单例组件
│   │   │   ├── database.php       # 数据库相关组件
│   │   │   ├── log.php            # 日志相关组件
│   │   │   └── cache.php          # 缓存组件，可继续添加其他组件
│   │   ├── dc.php                 # 环境配置项
│   │   └── constants.php          # 常量定义
│   │   ├── app.php                # 应用层配置
│   ├── Controller/                # 控制器层
│   │   └── IndexController.php    # 默认控制器
│   ├── Model/                     # 模型层
│   │   └── ClientModel.php        # 客户端模型
│   ├── Module/                    # 模块层
│   ├── Protocol/                  # 协议配置
│   │   └── conf.php               # 全局配置
│   ├── Router/                    # 路由配置
│   │   └── api.php                # 路由文件，不同模块定义不同文件
│   ├── Storage/                   # 存储目录
│   │   ├── Crontab/               # Cron service 的调度日志
│   │   ├── Logs/                  # 日志文件目录
│   │   └── Sql/                   # SQL 日志目录
│   ├── Scripts/                   # 脚本程序
│   │   └── Kernel.php             # 计划任务定义
│   ├── .env                       # 自动生成环境变量文件
│   ├── autoloader.php             # 自定义项目自动加载
│   ├── Event.php                  # 事件实现类
│   └── HttpServer.php             # HTTP 服务器
├── src/                           # 源码
├── cli.php                        # HTTP 应用启动入口文件
├── cron.php                       # 定时 worker 任务的多进程启动入口文件
├── daemon.php                     # 守护进程 worker 的多进程启动入口文件
├── script.php                     # 脚本启动入口文件
└── swag.php                       # 生成 swagger 接口文档入口文件
```

### 6. 启动服务

```bash
# 终端模式启动 (Ctrl+C 停止)
php cli.php start Test

# 守护进程模式
php cli.php start Test --daemon=1

# 查看运行状态
php cli.php status Test

# 停止服务
php cli.php stop Test

# 强制停止
php cli.php stop Test --force=1

# 重启服务
php cli.php restart Test
```

### 7. 访问应用

浏览器访问: `http://localhost:9501`

默认将看到欢迎页面: **Hello, Welcome to Swoolefy Framework!**

---

## 🏛️ 架构设计

### 进程模型

```
┌─────────────────────────────────────────────────────┐
│              Master Process (主进程)                 │
│  - 管理 Reactor 线程                                  │
│  - 接收并分发客户端连接                              │
└──────────────┬──────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────┐
│              Master Process (主进程)                     │
│  - 管理 Reactor 线程                                      │
│  - 接收并分发客户端连接                                  │
└──────────────┬──────────────────────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────────────────────┐
│              Manager Process (管理进程)                  │
│  - 管理 Worker 进程池                                     │
│  - 管理 Task 进程池                                       │
│  - 管理自定义进程 (通过 addProcess 拉起)                  │
│  - 进程重启和监控                                        │
└──────────┬────────────────────┬─────────────────────────┘
           │                    │
           ├───────────┬────────┴──────────┐
           │           │                    │
    ┌──────▼──────┐ ┌──▼──────────┐ ┌──────▼──────────┐
    │   Worker    │ │    Task     │ │  User Process   │
    │  Processes  │ │  Processes  │ │  (MainProcess)  │
    │  (业务处理)  │ │ (异步任务)   │ │  (管理进程)      │
    │             │ │             │ │                 │
    │ - onRequest │ │ - onTask    │ │ 通过 MainManager│
    │ - onConnect │ │             │ │ 拉起多个 Worker │
    │ - onReceive │ │             │ │                 │
    │             │ │             │ │ - Cron 任务管理  │
    │ 协程池/组件池│ │             │ │ - Daemon 常驻   │
    │ - DB 连接池  │ │             │ │ - 动态进程管理  │
    │ - Redis 池  │ │             │ │                 │
    │ - Curl 池   │ │             │ │ run() -> start()│
    └─────────────┘ └─────────────┘ └──────┬──────────┘
                                           │
                          ┌────────────────┼───────────────┐
                          │                │               │
                   ┌──────▼─────┐   ┌──────▼─────┐ ┌──────▼─────┐
                   │   Cron     │   │   Daemon   │ │   Script   │
                   │  Workers   │   │  Workers   │ │  Workers   │
                   │ (定时任务)  │   │ (常驻进程)  │ │ (脚本进程)  │
                   │            │   │            │ │            │
                   │ - 定时调度  │   │ - 消息消费 │ │ - 临时脚本 │
                   │ - 任务队列  │   │ - 数据处理 │ │ - 数据迁移 │
                   │ - URL 请求  │   │ - 实时计算 │ │ - 修复工具 │
                   └────────────┘   └────────────┘ └────────────┘
```

**进程层级说明:**

1. **Master Process**: 最高层级，管理 Reactor 线程和连接分发
2. **Manager Process**: 第二层级，统一管理所有子进程
3. **Worker/Task/User Process**: 第三层级，由 Manager 直接管理
4. **Cron/Daemon/Script Workers**: 第四层级，由 User Process (MainProcess) 通过 `MainManager::start()` 拉起

### 请求处理流程

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
│    - 加载路由配置       │
│    - 匹配路由           │
└───────────┬────────────┘
            │
            ↓
┌────────────────────────────────┐
│ 4. 执行中间件 (Middleware)     │
│    - beforeHandle (前置中间件)  │
│    - 验证/鉴权/CORS 等          │
│    - 请求参数处理              │
└───────────┬────────────────────┘
            │
            ↓
┌────────────────────────────────┐
│ 5. 调用控制器 Action            │
│    - Controller::action()      │
│    - 业务逻辑处理              │
└───────────┬────────────────────┘
            │
            ↓
┌─────────────────────────────────────┐
│ 6. 执行业务 (Business Logic)         │
│                                     │
│  ┌─────────────────────────────┐   │
│  │ goApp(function() {          │   │
│  │     // 协程并发处理          │   │
│  │     - DB 查询               │   │
│  │     - Redis 操作            │   │
│  │     - HTTP 请求             │   │
│  │     - 文件 IO               │   │
│  │ })                          │   │
│  │                             │   │
│  │ Parallel::run(50, $list,    │   │
│  │     function($item) {       │   │
│  │         // 限制并发数处理    │   │
│  │     }                       │   │
│  │ )                           │   │
│  └─────────────────────────────┘   │
│                                     │
│  - 协程调度器自动切换               │
│  - IO 密集型任务异步执行             │
│  - CPU 继续执行其他协程              │
└───────────┬─────────────────────────┘
            │
            ↓
┌────────────────────────┐
│ 7. 后置中间件          │
│    - afterHandle       │
│    - 响应格式化        │
│    - 日志记录          │
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

### 协程单例隔离机制

```php
// Application.php - 协程上下文管理
class Application
{
    protected static $apps = [];  // 以协程 ID 为 key 存储实例
    
    public static function setApp(App $App): bool
    {
        $cid = $App->getCid();  // 获取当前协程 ID
        self::$apps[$cid] = $App;  // 绑定到该协程
        return true;
    }
    
    public static function getApp(?int $coroutineId = null)
    {
        $cid = $coroutineId ?: \Swoole\Coroutine::getCid();
        return self::$apps[$cid] ?? null;
    }
}
```

**协程隔离示意图:**

```
协程 A (cid=1001)              协程 B (cid=1002)
     ↓                              ↓
App Instance A                App Instance B
     ↓                              ↓
containers['redis'] A         containers['redis'] B
     ↓                              ↓
Redis Object A                Redis Object B
(独立 Socket 连接)              (独立 Socket 连接)
```

---

## 📁 核心组件

### 1. 路由系统

支持类似 Laravel 的分组路由和中间件:

```php
// Router/api.php
use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

// 简单路由
Route::get('/index/index', [
    'beforeHandle' => function(RequestInput $request) {
        // 前置处理
        Context::set('trace_id', uniqid());
    },
    
    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
    
    'afterHandle' => function(RequestInput $request) {
        // 后置处理
    },
]);

// 分组路由
Route::group([
    'prefix' => 'api',
    'middleware' => [
        \Test\Middleware\Route\AuthMiddleware::class,
        \Test\Middleware\Route\CorsMiddleware::class,
    ]
], function () {
    
    Route::post('/user/create', [
        'dispatch_route' => [\Test\Controller\UserController::class, 'create'],
        'validation_rules' => [
            'username' => 'required|string|max:50',
            'email' => 'required|email',
        ]
    ]);
    
    Route::get('/user/list', [
        'dispatch_route' => [\Test\Controller\UserController::class, 'list'],
    ]);
});
```

### 2. 控制器

```php
<?php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;

class IndexController extends BController 
{
    /**
     * 首页 Action
     */
    public function index()
    { 
        // 返回 JSON
        return $this->returnJson([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'message' => 'Welcome to Swoolefy!',
                'time' => time(),
            ]
        ]);
    }
}
```

### 3. 数据库 ORM

#### 基础查询

```php
use Test\App;

// 获取 DB 实例 (协程单例)
$db = App::getDb();

// 查询列表
$users = $db->newQuery()
    ->table('users')
    ->where('status', 1)
    ->where('age', '>', 18)
    ->field(['id', 'username', 'email'])
    ->orderBy('id', 'DESC')
    ->limit(0, 20)
    ->select();

// 查询单条
$user = $db->newQuery()
    ->table('users')
    ->where('id', 100)
    ->find();

// 聚合查询
$count = $db->newQuery()
    ->table('users')
    ->where('status', 1)
    ->count();
```

#### 事务处理

```php
$db = App::getDb();

try {
    $db->beginTransaction();
    
    // 插入订单
    $orderId = $db->newQuery()
        ->table('orders')
        ->insert([
            'user_id' => 100,
            'amount' => 99.99,
            'status' => 0,
        ]);
    
    // 扣减库存
    $db->newQuery()
        ->table('products')
        ->where('id', 50)
        ->update([
            'stock' => \Swoolefy\Core\Db\Expression::raw('stock - 1'),
        ]);
    
    $db->commit();
    
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

### 4. Redis 组件

```php
use Test\App;

// 获取 Redis 实例
$redis = App::getRedis();

// 基本操作
$redis->set('name', 'bingcool');
$name = $redis->get('name');

// Hash 操作
$redis->hSet('user:100', 'name', '张三');
$redis->hSet('user:100', 'age', 25);
$name = $redis->hGet('user:100', 'name');

// List 操作
$redis->lPush('queue', 'task1');
$redis->lPush('queue', 'task2');
$task = $redis->rPop('queue');

// 发布订阅
$redis->publish('channel:news', '最新消息内容');
```

### 5. 协程并发

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

// 等待所有任务完成并获取结果
$results = $parallel->runWait(10.0);  // 超时 10 秒

// $results = [
//     'key_0' => [...],
//     'key_1' => [...],
//     ...
// ]
```

#### Parallel::run 迭代并发

```php
use Swoolefy\Core\Coroutine\Parallel;

// 分批处理大数据集
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
    go(function() use ($wg, $i) {
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

### 6. 连接池配置

```php
// Config/app.php
return [
    // 连接池配置
    'component_pools' => [
        'db' => [
            'max_pool_num' => 10,        // 最大池数量
            'max_push_timeout' => 2,     // 入池等待超时 (秒)
            'max_pop_timeout' => 1,      // 出池等待超时 (秒)
            'max_life_timeout' => 300,   // 对象存活时间 (秒)
            'enable_tick_clear_pool' => 0, // 是否定时清空池
        ],
        'redis' => [
            'max_pool_num' => 20,
            'max_push_timeout' => 2,
            'max_pop_timeout' => 1,
            'max_life_timeout' => 300,
        ]
    ],
    
    // 默认数据库
    'default_db' => 'db',
];
```

### 7. 自定义进程

#### 创建进程类

```php
// Process/Kafka/ConsumerKafka.php
namespace Test\Process\Kafka;

use Swoolefy\Core\Process\AbstractProcess;
use Swoole\Process as SwooleProcess;

class ConsumerKafka extends AbstractProcess
{
    /**
     * 进程主逻辑
     */
    public function handle()
    {
        // 初始化 Kafka 消费者
        $consumer = $this->initKafkaConsumer();
        
        while (true) {
            try {
                // 消费消息
                $message = $consumer->consume(1000);
                
                if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                    // 处理消息
                    $this->processMessage($message);
                    
                    // 提交偏移量
                    $consumer->commit($message);
                }
                
                // 检查是否需要退出
                if ($this->isExit()) {
                    break;
                }
                
            } catch (\Throwable $e) {
                $this->log->error('Kafka consume error: ' . $e->getMessage());
                sleep(1);
            }
        }
    }
    
    /**
     * 进程关闭前的清理
     */
    public function onShutdown()
    {
        $this->log->info('Kafka consumer shutdown');
    }
}
```

#### 注册进程

```php
// Event.php
public function onInit()
{
    // 添加 Kafka 消费进程
    ProcessManager::getInstance()->addProcess(
        'kafka-consumer',                      // 进程名称
        \Test\Process\Kafka\ConsumerKafka::class,  // 进程类
        true,                                  // 是否异步
        [],                                    // 构造参数
        null,                                  // 扩展数据
        true                                   // 是否启用协程
    );
    
    // 添加定时进程
    ProcessManager::getInstance()->addProcess(
        'tick-process',
        \Test\Process\TickProcess\Tick::class,
        true,
        [],
        null,
        true
    );
}
```

#### 进程间通信

```php
// Worker 进程向自定义进程发送消息
$process = ProcessManager::getInstance()->getProcessByName('kafka-consumer');

// 写入数据
$process->write([
    'action' => 'stop',
    'reason' => 'maintenance',
]);

// 读取返回 (带超时)
$msg = ProcessManager::getInstance()->readByProcessName('kafka-consumer', 3.0);
var_dump($msg);
```

---

## 🔧 高级特性

### 1. 定时任务 (Cron)

#### 创建定时任务服务

```bash
# 生成 Cron 服务目录
php script.php start Test --c=gen:cron:service
```

#### 配置定时任务

编辑 `Test/cron.yaml`:

```yaml
- name: "每日数据清理"
  rule: "0 2 * * *"           # 每天凌晨 2 点
  type: "fork"                # fork 新进程执行
  command: "\\Test\\Scripts\\CleanDataScript"
  
- name: "心跳检测"
  rule: "*/5 * * * *"         # 每 5 分钟
  type: "url"                 # HTTP 请求
  url: "http://localhost:9501/health/check"
  
- name: "报表生成"
  rule: "0 0 * * 0"           # 每周日凌晨
  type: "local"               # 当前进程内执行
  command: "\\Test\\Scripts\\ReportScript"
```

#### 启动定时任务服务

```bash
# 启动 Cron 服务
php cron.php start Test

# 守护进程模式
php cron.php start Test --daemon=1

# 重启
php cron.php restart Test

# 停止
php cron.php stop Test
```

### 2. 守护进程 (Daemon)

#### 创建守护进程服务

```bash
# 生成 Daemon 服务目录
php script.php start Test --c=gen:daemon:service
```

#### 配置常驻进程

编辑 `Test/WorkerDaemon/worker_daemon_conf.php`:

```php
return [
    'data-collector' => [
        'process_class' => \Test\WorkerDaemon\Datahub\DataCollector::class,
        'process_nums' => 4,              // 进程数
        'max_request' => 10000,           // 每个进程最大请求数
        'enable_coroutine' => true,       // 启用协程
    ],
    
    'order-processor' => [
        'process_class' => \Test\WorkerDaemon\Order\OrderProcessor::class,
        'process_nums' => 8,
        'max_request' => 5000,
        'enable_coroutine' => true,
    ],
];
```

#### 启动守护进程

```bash
# 启动 Daemon 服务
php daemon.php start Test

# 守护进程模式
php daemon.php start Test --daemon=1

# 查看状态
php daemon.php status Test
```

### 3. WebSocket 服务器

#### 配置 WebSocket

```php
// Protocol/conf.php
return [
    'setting' => [
        'open_websocket_protocol' => true,
        'websocket_compression' => true,
    ],
];
```

#### WebSocket 事件处理

```php
// Controller/WsController.php
namespace Test\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class WsController extends BController
{
    /**
     * 连接建立
     */
    public function onOpen()
    {
        $fd = $this->request->fd;
        echo "Client #{$fd} connected\n";
        
        // 发送欢迎消息
        $this->push($fd, [
            'type' => 'welcome',
            'message' => 'Connected to server',
        ]);
    }
    
    /**
     * 接收消息
     */
    public function onMessage($frame)
    {
        $fd = $this->request->fd;
        $data = json_decode($frame->data, true);
        
        echo "Received from #{$fd}: " . $frame->data . "\n";
        
        // 处理消息并回复
        $this->push($fd, [
            'type' => 'reply',
            'message' => 'Message received',
            'data' => $data,
        ]);
    }
    
    /**
     * 连接关闭
     */
    public function onClose()
    {
        $fd = $this->request->fd;
        echo "Client #{$fd} disconnected\n";
    }
}
```

### 4. RPC 服务 (TCP)

#### 服务端配置

```php
// Protocol/conf.php
return [
    'packet' => [
        'server' => [
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 1024 * 1024,
        ],
    ],
];
```

#### RPC 服务实现

```php
// Rpc/UserService.php
namespace Test\Rpc;

use Swoolefy\Core\Swoole;

class UserService
{
    /**
     * 获取用户信息
     */
    public function getUserInfo(array $params)
    {
        $userId = $params['user_id'] ?? 0;
        
        $db = \Test\App::getDb();
        $user = $db->newQuery()
            ->table('users')
            ->where('id', $userId)
            ->find();
        
        return [
            'code' => 200,
            'data' => $user,
        ];
    }
    
    /**
     * 创建用户
     */
    public function createUser(array $params)
    {
        $db = \Test\App::getDb();
        $userId = $db->newQuery()
            ->table('users')
            ->insert([
                'username' => $params['username'],
                'email' => $params['email'],
            ]);
        
        return [
            'code' => 200,
            'data' => ['user_id' => $userId],
        ];
    }
}
```

#### RPC 客户端调用

```php
use Common\Library\Rpc\Client\RpcClient;

$client = new RpcClient([
    'host' => '127.0.0.1',
    'port' => 9505,
    'timeout' => 3.0,
]);

// 调用远程方法
$result = $client->call('UserService.getUserInfo', [
    'user_id' => 100,
]);

var_dump($result);
```

### 5. OpenTelemetry 链路追踪

#### 配置 OpenTelemetry

编辑 `.env`:

```bash
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_TRACING_NAME=swoolefy-http-service
OTEL_EXPORTER_OTLP_ENDPOINT=http://jaeger:4317
OTEL_RESOURCE_ATTRIBUTES=service.name=swoolefy,service.version=1.0.0
```

#### 自动追踪

框架已自动集成 OpenTelemetry，无需额外配置即可追踪:

- HTTP 请求入口
- 数据库查询
- Redis 操作
- Curl 请求
- 自定义 Span

#### 手动添加 Span

```php
use Common\Library\OpenTelemetry\API\Globals;

$tracer = Globals::tracerProvider()->getTracer('app');

$span = $tracer->spanBuilder('business_logic')
    ->setAttribute('user.id', 100)
    ->setAttribute('action', 'create_order')
    ->startSpan();

try {
    // 业务逻辑
    $this->createOrder();
    
    $span->setStatus(\Common\Library\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
} catch (\Throwable $e) {
    $span->recordException($e);
    $span->setStatus(\Common\Library\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
    throw $e;
} finally {
    $span->end();
}
```

### 6. Swagger API 文档

#### 使用 PHP8 Attribute注解

```php
<?php
namespace Test\Module\Order\Validation;

use OpenApi\Attributes as OA;
use Test\Module\Swag;

class UserOrderValidation
{
    #[OA\Post(
        path: '/user/user-order/userList',
        summary: '订单列表',
        description: '获取订单列表内容',
        tags: [Swag::MODULE_TAG_ORDER],
        security: [['apiKeyAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    type: 'object',
                    required: ['name', 'email'],
                    properties: [
                        new OA\Property(
                            property: 'name', 
                            type: 'string', 
                            description: '用户名称'
                        ),
                        new OA\Property(
                            property: 'email', 
                            type: 'string', 
                            description: '邮箱地址',
                            format: 'email'
                        ),
                        new OA\Property(
                            property: 'age', 
                            type: 'integer', 
                            description: '年龄',
                            minimum: 0,
                            maximum: 150
                        ),
                    ]
                )
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: '操作成功',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'code', type: 'integer'),
                new OA\Property(property: 'msg', type: 'string'),
                new OA\Property(property: 'data', type: 'array'),
            ]
        )
    )]
    public function userList(): array
    {
        return [
            'rules' => [
                'name' => 'required|string|max:50',
                'email' => 'required|email',
                'age' => 'integer|min:0|max:150',
            ],
            'messages' => [
                'name.required' => '名称必填',
                'email.email' => '邮箱格式不正确',
            ]
        ];
    }
}
```

#### 生成 Swagger 文档

```bash
# 生成 openapi.yaml
php swag.php Test

# 访问 Swagger UI
http://localhost:9501/swagger.html
```

---

## 📊 性能优化建议

### 1. 连接池预热

在 Worker 启动时预创建连接:

```php
public function onWorkerStart($server, $worker_id)
{
    // 预创建连接池
    if ($worker_id == 0) {
        $poolConfig = config('component_pools');
        
        foreach ($poolConfig as $name => $config) {
            CoroutinePools::getInstance()->warmUp($name, $config['max_pool_num']);
        }
    }
}
```

### 2. 日志批量写入

```php
class AsyncLogger
{
    protected $buffer = [];
    protected $maxBuffer = 100;
    
    public function info(string $message)
    {
        $this->buffer[] = [
            'level' => 'info',
            'message' => $message,
            'time' => time(),
        ];
        
        if (count($this->buffer) >= $this->maxBuffer) {
            $this->flush();
        }
    }
    
    protected function flush()
    {
        go(function() {
            $content = json_encode($this->buffer, JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($this->logFile, $content, FILE_APPEND);
            $this->buffer = [];
        });
    }
}
```

### 3. 路由优化

使用精确匹配优先策略:

```php
class RouteOptimizer
{
    protected $exactRoutes = [];
    protected $paramRoutes = [];
    
    public function optimize()
    {
        foreach (Route::$routeMap as $uri => $methods) {
            if (strpos($uri, '{') !== false) {
                // 参数路由转为正则
                $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $uri);
                $this->paramRoutes[$pattern] = $methods;
            } else {
                // 精确路由
                $this->exactRoutes[$uri] = $methods;
            }
        }
    }
    
    public function match(string $method, string $uri)
    {
        // O(1) 精确匹配
        if (isset($this->exactRoutes[$uri][$method])) {
            return $this->exactRoutes[$uri][$method];
        }
        
        // 正则匹配
        foreach ($this->paramRoutes as $pattern => $methods) {
            if (preg_match('#^' . $pattern . '$#', $uri)) {
                return $methods[$method] ?? null;
            }
        }
        
        return null;
    }
}
```

---

## 🛠️ 命令行工具

### 内置命令

```bash
# 启动应用
php cli.php start Test

# 停止应用
php cli.php stop Test [--force=1]

# 重启应用
php cli.php restart Test

# 查看状态
php cli.php status Test

# 发送消息到进程
php cli.php send Test --process=kafka-consumer --data='{"action":"reload"}'
```

### 脚本命令

```bash
# 生成 Cron 服务
php script.php start Test --c=gen:cron:service

# 生成 Daemon 服务
php script.php start Test --c=gen:daemon:service

# 生成 Swagger 文档
php swag.php Test

# 清理缓存
php cli.php clear Test --cache=all
```

---

## 📝 最佳实践

### 1. 项目结构规范

```
myproject/
├── Test/
│   ├── Config/              # 配置层
│   ├── Controller/          # 控制器层
│   ├── Model/               # 模型层
│   ├── Service/             # 业务逻辑层
│   ├── Repository/          # 数据访问层
│   ├── Middleware/          # 中间件层
│   ├── Process/             # 自定义进程
│   ├── Scripts/             # 脚本程序
│   └── Validation/          # 验证规则
├── Common/                  # 公共库
│   ├── Library/             # 第三方库封装
│   └── Helper/              # 辅助函数
└── storage/                 # 存储目录
    ├── logs/                # 日志文件
    ├── cache/               # 缓存文件
    └── temp/                # 临时文件
```

### 2. 命名规范

- **控制器**: `UserController`, `OrderController` (后缀 Controller)
- **模型**: `User`, `Order` (单数名词)
- **服务**: `UserService`, `OrderService` (后缀 Service)
- **中间件**: `AuthMiddleware`, `CorsMiddleware` (后缀 Middleware)
- **进程**: `KafkaConsumer`, `TimerProcess` (描述性命名)

### 3. 异常处理分层

```php
try {
    // 业务逻辑
    $this->createOrder();
    
} catch (BusinessException $e) {
    // 业务异常 - 返回用户友好提示
    return $this->returnJson([
        'code' => 400,
        'msg' => $e->getMessage(),
    ]);
    
} catch (ValidationException $e) {
    // 验证异常
    return $this->returnJson([
        'code' => 422,
        'errors' => $e->getErrors(),
    ]);
    
} catch (\Throwable $e) {
    // 系统异常 - 记录日志并返回通用错误
    Log::error($e);
    return $this->returnJson([
        'code' => 500,
        'msg' => 'Server Error',
    ]);
}
```

### 4. 资源清理规范

```php
public function end()
{
    try {
        // 1. 写入日志
        $this->handleLog();
        
        // 2. 归还连接池
        $this->pushComponentPools();
        
        // 3. 清理组件
        $this->clearComponent(null, true);
        
        // 4. 移除应用实例
        Application::removeApp();
        
        // 5. 结束响应
        if (!$this->isEnd && $this->swooleResponse->isWritable()) {
            @$this->swooleResponse->end();
        }
        
    } finally {
        // 确保资源被清理
        ZFactory::removeInstance();
    }
}
```

---

## 🤝 贡献指南

欢迎提交 Issue 和 Pull Request!

### 开发环境搭建

```bash
# Fork 项目
git clone git@github.com:your-username/swoolefy.git

# 安装依赖
composer install

# 运行测试
composer test
```

### 代码规范

遵循 PSR-12 编码规范，使用 PHPCS 检查:

```bash
composer cs-check
composer cs-fix
```

---

## 📄 License

MIT License  
Copyright (c) 2017-2025 zengbing huang

---

## 🔗 相关链接

- **GitHub**: https://github.com/bingcool/swoolefy
- **Library 组件库**: https://github.com/bingcool/library
- **Swoole 官网**: https://www.swoole.com
- **Composer**: https://packagist.org/packages/bingcool/swoolefy
- **问题反馈**: https://github.com/bingcool/swoolefy/issues

---

## 👨‍💻 作者

**zengbing huang**  
Email: 2437667702@qq.com

---

## 🙏 致谢

感谢以下开源项目:

- [Swoole](https://github.com/swoole/swoole-src)
- [Laravel](https://laravel.com)
- [Symfony Console](https://symfony.com)
- [OpenTelemetry](https://opentelemetry.io)
- [Swagger/OpenAPI](https://swagger.io)

---

**Happy Coding with Swoolefy! 🚀**
