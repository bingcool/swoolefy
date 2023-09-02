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
swoolefy是一个基于swoole实现的轻量级高性能的常驻内存型的协程级应用服务框架，
高度支持httpApi，websocket，udp服务器，以及基于tcp实现可扩展的rpc服务，worker多进程消费模型  
同时支持composer包方式安装部署项目。基于实用主义设计出发，swoolefy抽象Event事件处理类，
实现与底层的回调的解耦，支持协程单例调度，同步|异步调用，全局事件注册，心跳检查，异步任务，多进程(池)，连接池等，
内置```log、session、mysql、pgsql、redis、mongodb、kafka、amqp```等常用组件等.    

### 建议版本
swoolefy-5.0+ 版本：      
目前主分支，最低要求```php8.0+，swoole5.0+（或者swoole-cli-v5.0+)```, 或者也可以使用```swoole-cli-v4.8+```, 因为其内置php8.1+  

swoolefy-4.8-lts 版本：    
长期维护分支，最低要求```php >= php7.3 && php < php8.0```, 推荐直接swoole-v4.8+，需要通过源码编译安装swoole

选择哪个版本？  
1、如果确定项目是使用php8+的，那么直接选择 ```swoole-v5.0+```, 以上源码来编译安装或者直接使用```swoole-cli-v5.0```，然后选择 ```bingcool/swoolefy:~5.0.14``` 作为项目分支

2、如果确定项目是使用 ```php7.3 ~ php7.4``` 的，那么选择 swoole-v4.8+ 版本来进行编译安装(不能直接使用 swoole-cli-v4.8+ 了, 因为其内置的是php8.1，与你的项目的php7不符合)
所有只能通过编译swoole源码的方式来生成swoole扩展，然后选择 ```bingcool/swoolefy:^4.8.14``` 作为项目分支

### 实现的功能特性    

基础特性
- [x] 支持架手脚一键创建项目           
- [x] 支持分组路由, 路由middleware, 前置路由组件, 后置路由组件,多模块应用                 
- [x] 支持composer的PSR-4规范，实现PSR-3的日志接口     
- [x] 支持自定义注册不同根命名空间，快速多项目部署          
- [x] 支持httpServer，实用轻量Api接口开发     
- [x] 支持多协议websocketServer、udpServer、mqttServer      
- [x] 支持基于tcp实现的rpc服务，开放式的系统接口，可自定义协议数据格式，并提供rpc-client协程组件
- [x] 支持DI容器，组件IOC、配置化，Channel公共组件池            
- [x] 支持协程单例注册,协程上下文变量寄存    
- [x] 支持mysql、postgreSql、redis协程组件          
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
- [x] 支持热更新reload worker 监控以及更新                 
- [x] 支持定时的系统信息采集，并以订阅发布，udp等方式收集至存贮端    
- [x] 支持命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架   

高级特性
- [x] 支持crontab的local调用和fork独立进程的计划任务    
    
    | 支持方式  |                 说明                 |
    |:----------------------------------:|:---:|
    | local |            自定义进程内定时执行代码            |
    | fork  |   自定义进程定时拉起一个新的进程，由新的进程去支持任务，可异步   |
    | url   | 自定义进程定时发起远程url请求，可设置callback回调处理结果 |

- [x] 支持worker下后台daemon模式的多进程协程消费模型,包括进程自动拉起，进程数动态调整，进程健康状态监控     
- [x] 支持console终端脚本模式，跑完脚本自动退出，可用于修复数据、数据迁移等临时脚本功能      
- [ ] 支持分布式服务注册（zk，etcd）       

### 常用组件
| 组件名称             | 安装                                                    | 说明                                                  |
|------------------|-------------------------------------------------------|-----------------------------------------------------|
| predis           | composer require predis/predis:~1.1.7                 | predis组件、或者Phpredis扩展                               |
| mongodb          | composer require mongodb/mongodb:~1.3                 | mongodb组件，需要使用mongodb必须安装此组件                        |
| rpc-client       | composer require bingcool/rpc-client:dev-master       | swoolefy的rpc客户端组件，当与rpc服务端通信时，需要安装此组件，支持在php-fpm中使用 |
| cron-expression  | composer require dragonmantank/cron-expression:~3.3.0 | crontab计划任务组件，类似Linux的crobtab                       |  
| redis lock       | composer require malkusch/lock                        | Redis锁组件                                            |
| amqp             | composer require php-amqplib/php-amqplib:~3.5.0       | amqp php原生实现amqp协议客户端                               |  
| ffmpeg           | composer require php-ffmpeg/php-ffmpeg:~1.1.0         | php proc-open 调用ffmpeg处理音视频                         |  
| validate         | composer require vlucas/valitron                      | validate数据校验组件                                      |     
| bingcool/library | composer require bingcool/library                     | library组件库                                          |  

### bingcool/library 是swoolefy require 内置库，专为swoole协程实现的组件库        
实现了包括：    
- [x] Db ORM Model 组件(支持mysql,postSql,sqlite,Oracle)
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
- [x] Db 、Redis、Curl协程连接池组件
- [x] UUid 分布式自增id组件  
- [x] Curl基础组件    
- [x] Jwt 组件   
- [x] Validate组件    
   
github: https://github.com/bingcool/library    


### 一、安装 

1、先配置环境变量
```
// 独立物理机或者云主机配置系统环境变量
vi /etc/profile
在/etc/profile末尾添加一行标识环境，下面是支持的4个环境,框架将通过这个环境变量区分环境，加载不同的配置
export SWOOLEFY_CLI_ENV='dev'  // 开发环境
export SWOOLEFY_CLI_ENV='test' // 测试环境
export SWOOLEFY_CLI_ENV='gra'  // 灰度环境
export SWOOLEFY_CLI_ENV='prd'  // 生产环境
// 最后是配置生效
source /etc/profile

```
```
// 如果是通过dockerfile 创建容器的, 可以根据不同环境生成的内置环境变量不同镜像，每个不同的环境镜像可以用在不同环境，代码将通过这个环境变量区分环境，加载不同的配置
ENV SWOOLEFY_CLI_ENV dev

```
2、创建项目
```
// 下载代码到到你的自定义目录，这里定义为myproject
composer create-project bingcool/swoolefy:~5.0 myproject
```

### 二、添加项目入口启动文件cli.php,并定义你的项目目录，命名为App

```
<?php
// 在myproject目录下添加cli.php, 这个是启动项目的入口文件
include './vendor/autoload.php';

define('IS_DAEMON_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('IS_CLI_SCRIPT', 0);
date_default_timezone_set('Asia/Shanghai');

define('APP_NAMES', [
     // 你的项目命名为App，对应协议为http协议服务器，支持多个项目的，只需要在这里添加好项目名称与对应的协议即可
    'App' => 'http', 
    'Test' => 'http
]);

include './swoolefy';

```

### 三、执行创建你定义的App项目

```
// 你定义的项目目录是App, 在myproject目录下执行下面命令行

swoole-cli cli.php create App 或者 php cli.php create App   

// 执行完上面命令行后，将会自动生成App项目目录以及内部子目录
myproject
|—— App  // 应用项目目录
|     |── Config       // 应用配置
|     |   |__ component // 协程单例组件
|     |      |—— database.php //数据库相关组件
|     |      |—— log.php     // 日志相关组件
|     |      |—— cache.php   // 缓存组件，可以继续添加其他组件，命名自由 
|     │   ├── dc-dev.php   //dev环境配置项
|     │   ├── dc-gra.php   //gre环境配置项
|     │   ├── dc-prd.php   //prd环境配置项
|     │   ├── dc-test.php  //test环境配置项
|     │   └── defines.php
|     |   |—— config.php    // 应用层配置
|     |   |—— Component.php //协程单例组件
|     |
|     ├── Controller
|     │   └── IndexController.php // 控制器层
|     ├── Model
|     │   └── ClientModel.php
|     ├── Module        // 模块层
|     ├── Protocol      // 协议配置
|     │   ├── conf.php  // 全局配置
|     │
|     ├── Router
|     │   └── Api.php  // 路由文件，不同模块定义不同文件即可
|     |—— Storage
|     |   |—— Logs  // 日志文件目录
|     |   |—— Sql   // sql日志目录
|     │—— autoloader.php // 自定义项目自动加载
|     |—— Event.php      // 事件实现类
|     |—— HttpServer.php // http server
|    
|——— src //源码
|——— cli.php // 应用启动入口文件
|——— cron.php // 定时worker任务的多进程启动入口文件
|——— daemon.php // 守护进程worker的多进程启动入口文件
|——— script.php // 脚本启动入口文件

```

### 四、启动应用项目

```
// 终端启动 ctl+c 停止进程
php cli.php start App
或者    
swoole-cli cli.php start App

// 守护进程方式启动,添加-D参数控制
php cli.php start App -D
或者  
swooole-cli cli.php start App -D

// 停止进程
php cli.php stop App
或者   
swooole-cli cli.php stop App

// 查看进程状态
swooole-cli cli.php status App

```

### 五、访问

默认端口是9502,可以通过 http://localhost:9502 访问默认控制器
```
<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

// 默认生成的IndexController
class IndexController extends BController {

    public function index() {
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }
}

```

至此一个最简单的http的服务就创建完成了，更多例子请参考项目下Test的demo


### 定义组件

应用层配置文件：
Config/config.php

```
<?php

return [

    // db|redis连接池
    'enable_component_pools' => [
        // 取components的`DB`组件名称相对应
        'db' => [
            'pools_num' => 5, // db实例数
            'push_timeout' => 2, // db实例进入channel池最长等待时间，单位s
            'pop_timeout' => 1, // db实例出channel池最长等待时间，单位s.在规定时间内获取不到db对象，将降级为实时创建db实例
            'live_time' => 10 // db实例的有效期，单位s.过期后将被掉弃，重新创建新DB实例
        ],
    
        // 取components的`redis`组件名称相对应
        'redis' => [
            'pools_num' => 5,
            'push_timeout' => 2,
            'pop_timeout' => 1,
            'live_time' => 10
        ]
    ],
    
     // default_db
    'default_db' => 'db',

    // 记载组件配置
    'components' => \Swoolefy\Core\SystemEnv::loadComponent()
    
    // 其他配置
    ......
]

```

组件Component.php:
```

$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

return [
    // 用户行为记录的日志
    'log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon.log';
        }else if (isScriptService()) {
            $logFilePath = LOG_PATH.'/script.log';
        }else if (isCronService()) {
            $logFilePath = LOG_PATH.'/cron.log';
        } else {
            $logFilePath = LOG_PATH.'/runtime.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // 系统捕捉异常错误日志
    'error_log' => function($name) {
        $logger = new \Swoolefy\Util\Log($name);
        $logger->setChannel('application');
        if(isDaemonService()) {
            $logFilePath = LOG_PATH.'/daemon_error.log';
        }else if (isScriptService()) {
            $logFilePath = LOG_PATH.'/script_error.log';
        }else if (isCronService()) {
            $logFilePath = LOG_PATH.'/cron_error.log';
        } else {
            $logFilePath = LOG_PATH.'/error.log';
        }
        $logger->setLogFilePath($logFilePath);
        return $logger;
    },

    // MYSQL
    'db' => function() use($dc) {
        $db = new \Common\Library\Db\Mysql($dc['mysql_db']);
        return $db;
    },
    
    // Redis Cache
    'redis' => function() use($dc) {
        $redis = new \Common\Library\Cache\Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        return $redis;
    },
    
    // Predis Cache
    'predis' => function() use($dc) {
        $predis = new \Common\Library\Cache\predis([
            'scheme' => $dc['predis']['scheme'],
            'host'   => $dc['predis']['host'],
            'port'   => $dc['predis']['port'],
        ]);
        return $predis;
    }
    
```

### 使用组件
```
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


### 默认协议层全局配置文件 Protocol/conf.php

开发者可以根据实际使用适当调整配置项

```
$dc = \Swoolefy\Core\SystemEnv::loadDcEnv();

<?php

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
        'log_file'               => '/tmp/' . APP_NAME . '/swoole_log.txt',
        'pid_file'               => '/data/' . APP_NAME . '/log/server.pid',
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
### 路由文件（类似laravel路由）
Router/api.php
```
<?php

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件,多个按顺序执行
    'middleware' => [
        \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]
], function () {

    Route::get('/', [
        // 前置路由,闭包函数形式
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);


    Route::get('/index/index', [
        // 前置路由,闭包函数形式
        'beforeHandle1' => function(RequestInput $requestInput) {
            $name = $requestInput->getPostParams('name');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由1, 闭包函数形式
        'afterHandle1' => function(RequestInput $requestInput) {

        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

    ]);
});

```

### 数据库操作
```

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

### License
MIT   
Copyright (c) 2017-2023 zengbing huang    
