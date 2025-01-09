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
内置```log、session、mysql、pgsql、redis、mongodb、kafka、amqp、uuid、route midelware、cache、queue、rateLimit、traceId```等常用组件等.    

### 建议版本
swoolefy-5.1.x 版本：      
目前主分支，最低要求```php8.1+，swoole5.1.x``` 

swoolefy-4.8-lts 版本：    
长期维护分支，最低要求```php7.3 ~ php7.4, swoole4.8.x```, 推荐直接swoole-v4.8.13，需要通过源码编译安装swoole

选择哪个版本？  
1、如果确定项目是使用php8+的，那么直接选择 ```swoole-v5.1+```, 以上源码来编译安装或者直接使用```swoole-cli-v5.x```，然后选择 ```bingcool/swoolefy:~5.1.3``` 作为项目分支

2、如果确定项目是使用 ```php7.3 ~ php7.4``` 的，那么选择 swoole-v4.8+ 版本来进行编译安装(不能直接使用 swoole-cli-v4.8+ 了, 因为其内置的是php8.1，与你的项目的php7不符合)
所有只能通过编译swoole源码的方式来生成swoole扩展，然后选择 ```bingcool/swoolefy:^4.9.0``` 作为项目分支

3、依赖编译： ./configure --enable-openssl --enable-sockets --enable-swoole-curl --enable-swoole-pgsql

4、若不希望自己编译构建，也可以直接使用本目录下的Dockerfile来构建镜像:     
```
// 构建镜像
docker build -t swoolefy-php74:v1 .

// 启动容器
docker run -d -it --name=swoolefy-php74 -v swoolefy-php74:v1

```
### 实现的功能特性    

基础特性
- [x] 支持架手脚一键创建项目自动生成最小项目骨架         
- [x] 支持swagger一键生成api文档     
- [x] 支持分组路由, 路由中间件middleware, 前置路由组件, 后置路由组件middleware,多模块应用    
- [x] 支持自定义注册不同根命名空间，快速多项目部署          
- [x] 支持httpServer，实用轻量Api接口开发     
- [x] 支持多协议websocketServer、udpServer、mqttServer      
- [x] 支持基于tcp实现的rpc服务，开放式的系统接口，可自定义协议数据格式，并提供rpc-client协程组件
- [x] 支持DI容器，组件IOC、配置化，Channel公共组件池            
- [x] 支持协程单例注册,协程上下文变量寄存    
- [x] 支持mysql、postgreSql、redis协程组件   
- [x] 支持全局logger组件、trace链路追踪组件     
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
- [x] 支持热更新reload worker 监控以及更新                 
- [x] 支持定时的系统信息采集，并以订阅发布，udp等方式收集至存贮端    
- [x] 支持命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架, 并对外提供控制启动|停止|重启|查看状态的api接口，可开发成可视化控制页面    

高级特性
- [x] 支持cron计划任务模式. 类似crontab，支持local|fork|remote url三种方式      
    
    | 支持方式  |                          说明                           |
    |:-----------------------------------------------------:|:---:|
    | local |                     自定义进程内定时执行代码                      |
    | fork  | 自定义进程定时拉起一个新的进程，由新的进程去执行任务，可异步，类似laravel的schedule计划任务 |
    | url   |          自定义进程定时发起远程url请求，可设置callback回调处理结果           |

- [x] 支持daemon模式.worker下后台daemon模式的多进程协程消费模型,包括进程自动拉起，进程数动态调整，进程健康状态监控     
- [x] 支持console终端脚本模式. 跑完脚本自动退出，可用于修复数据、数据迁移等临时脚本功能      
- [ ] 支持分布式服务注册（zk，etcd）       

### 适配协程环境组件
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
| guzzlehttp       | composer require guzzlehttp/guzzle                    | guzzlehttp 组件                                       | 
| oauth 2.0        | composer require league/oauth2-server                 | oauth 2.0 授权认证组件                                    |   
| bingcool/library | composer require bingcool/library                     | library组件库                                          |  

### bingcool/library 是swoolefy require 内置库，专为swoole协程实现的组件库        
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
- [x] Curl基础组件    
- [x] Jwt 组件   
- [x] Validate 组件    
- [x] Encrypt 加密解密组件   
- [x] Captcha 验证码组件    
- [x] translation 国际化（I18N）    
   
github: https://github.com/bingcool/library    


### 一、安装 

1、先配置环境变量(必须设置)
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
ENV SWOOLEFY_CLI_ENV=dev

```
2、创建项目
```
// 下载代码到到你的自定义目录，这里定义为myproject
composer create-project bingcool/swoolefy:^4.9.3 myproject
```

### 二、添加项目入口启动文件cli.php,并定义你的项目目录，命名为App

```
<?php
// 在myproject目录下添加cli.php, 这个是启动项目的入口文件

include __DIR__.'/vendor/autoload.php';

$appName = ucfirst($_SERVER['argv'][2]);
// 定义app name
define('APP_NAME', $appName);
// 启动目录
defined('START_DIR_ROOT') or define('START_DIR_ROOT', __DIR__);
// 应用父目录
defined('ROOT_PATH') or define('ROOT_PATH',__DIR__);
// 应用目录
defined('APP_PATH') or define('APP_PATH',__DIR__.'/'.$appName);

registerNamespace(APP_PATH);

define('IS_WORKER_SERVICE', 0);
define('IS_DAEMON_SERVICE', 0);
define('IS_SCRIPT_SERVICE', 0);
define('IS_CRON_SERVICE', 0);
define('PHP_BIN_FILE','/usr/bin/php');

define('WORKER_START_SCRIPT_FILE', str_contains($_SERVER['SCRIPT_FILENAME'], $_SERVER['PWD']) ? $_SERVER['SCRIPT_FILENAME'] : $_SERVER['PWD'].'/'.$_SERVER['SCRIPT_FILENAME']);
define('WORKER_SERVICE_NAME', makeServerName($appName));
define('SERVER_START_LOG', '/tmp/workerfy/log/'.WORKER_SERVICE_NAME.'/start.log');

date_default_timezone_set('Asia/Shanghai');
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

// 启动前处理,比如加载.env
//$beforeFunc = function () {
//    try {
//        \Test\LoadEnv::load('192.168.1.101:8848','swoolefy','test','nacos-test','123456');
//    }catch (\Throwable $exception) {
//
//    }
//};

include __DIR__.'/swoolefy';


```

### 三、执行创建你定义的App项目

```
// 你定义的项目目录是App, 在myproject目录下执行下面命令行

php cli.php create App   
或者  
swoole-cli cli.php create App 


// 执行完上面命令行后，将会自动生成App项目目录以及内部子目录
myproject
|—— App  // 应用项目目录
|     |── Config       // 应用配置
|     |   |__ component // 协程单例组件
|     |      |—— database.php //数据库相关组件
|     |      |—— log.php     // 日志相关组件
|     |      |—— cache.php   // 缓存组件，可以继续添加其他组件，命名自由 
|     │   ├── dc.php   //环境配置项
|     │   └── constants.php
|     |   |—— app.php    // 应用层配置
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
|     │   └── api.php  // 路由文件，不同模块定义不同文件即可
|     |—— Storage
|     |   |—— Logs  // 日志文件目录
|     |   |—— Sql   // sql日志目录
|     |—— Scripts
|     |   |—— Kernel.php    // 计划任务定义    
|     |__ .env     // 自动生成环境变量文件
|     │—— autoloader.php // 自定义项目自动加载
|     |—— Event.php      // 事件实现类
|     |—— HttpServer.php // http server
|    
|——— src //源码
|——— cli.php // http应用启动入口文件
|——— cron.php // 定时worker任务的多进程启动入口文件
|——— daemon.php // 守护进程worker的多进程启动入口文件
|——— script.php // 脚本启动入口文件
|——— swag.php // 生成swagger接口文档入口文件

```

### 四、启动http应用项目

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

```
// 创建生成Cron定时计划任务服务,默认生成WorkerCron目录

php script.php start App --c=gen:cron:service

// 启动Cron服务,添加--daemon=1以守护进程启动
php cron.php start App

// 停止Cron服务
php cron.php stop App

```

```
// 创建生成Daemon常驻进程消费服务,默认生成WorkerDaemon目录

php script.php start App --c=gen:daemon:service

// 启动Daemon服务，添加--daemon=1以守护进程启动
php daemon.php start App 

// 停止Daemon服务
php daemon.php stop App


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
        // 最简单的协程单例，goApp()即可创建一个协程,在单例中的db,redis等其他注册的组件都是单例的，不同协程单例相互隔离  
        goApp(function() {
            var_dump('this is a coroutine single app test');
        });
        
        Application::getApp()->response->write('<h1>Hello, Welcome to Swoolefy Framework! <h1>');
    }
}

```

至此一个最简单的http的服务就创建完成了，更多例子请参考项目下Test的demo


### 定义组件

应用层配置文件：
Config/app.php

```
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

组件Component.php:
```

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
        $redis = new \Common\Library\Redis\Redis();
        $redis->connect($dc['redis']['host'], $dc['redis']['port']);
        return $redis;
    },
    
    // Predis Cache
    'predis' => function() use($dc) {
        $predis = new \Common\Library\Redis\predis([
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
### 路由文件（类似laravel路由）
Router/api.php
```
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

        // 前置路由,中间件数组类形式(推荐)
        'beforeHandle3' => [
            \Test\Middleware\Route\ValidLoginMiddleware::class,
            \Test\Middleware\Route\ValidLoginMiddleware::class,
        ],

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 前置路由,中间件数组类形式(推荐)
        'afterHandle3' => [
            \Test\Middleware\Route\ValidLoginMiddleware::class,
            \Test\Middleware\Route\ValidLoginMiddleware::class
        ],
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

### 协程单例，协程并发
```
// 协程单例使用goApp直接调用创建, 每个协程的DB，redis,kafka,mq的socket对象相互隔离，互不影响，代码通用

goApp(function() {
    $db = Application::getApp()->get('db');
    // 查询列表
    $db->newQuery()->table('tbl_users')->where('id','>', 1)->field(['id', 'user_name'])->limit(0,10)->select();
    // redis
    $redis = Application::getApp()->get('redis');
    $redis->set('name','bingcool')
})



// 协程并发-协程并发迭代数组处理数据(针对数据量大，无需关注返回数据)

$list = [
    [
        'name' => ’name-1'
    ],
    [
        'name' =>  ’name-2'
    ],
    [
        'name' =>  ’name-3'
    ],
    [
        'name' =>  ’name-4'
    ],
    [
        'name' =>  ’name-5'
    ]
];

// 并发2个协程-循环迭代数组-回调函数处理
Parallel::run(2, $list, function ($item) {
    var_dump($item['name']);
}, 0.01);



// 协程并发-协程并发处理并等待结果(针对并发量少，并且需要返回数据的)

$parallel = new Parallel();
$parallel->add(function () {
    sleep(2);
    return "阿里巴巴";
},'ali');

$parallel->add(function () {
    sleep(2);
    return "腾讯";
},'tengxu');

$parallel->add(function () {
    sleep(2);
    return "百度";
},'baidu');

$parallel->add(function () {
    sleep(5);
    return "字节跳动";
},'zijie');

// 并发等待返回数据
$result = $parallel->runWait(10);
array(4) {
  ["ali"]=>
  string(12) "阿里巴巴"
  ["tengxu"]=>
  string(6) "腾讯"
  ["baidu"]=>
  string(6) "百度"
  ["zijie"]=>
  string(12) "字节跳动"
}


```

### swagger接口文档生成

在Test/Module/Order/Validation下，每个文件对应一个Controller的方法，可以使用php8的attribute注解定义好接口，然后执行 php swag.php Test 即可自动生成openapi.yaml文件
在浏览器直接访问: http:127.0.0.1:9501/swagger.html

```
<?php
namespace Test\Module\Order\Validation;

use OpenApi\Attributes as OA;
use Test\Module\Swag;

class UserOrderValidation
{
    // Post请求的参数
    #[OA\Post(
        path: '/user/user-order/userList',
        summary:'订单保存',
        description:'保存订单',
        tags: [Swag::MODULE_TAG_ORDER], // 根据Swag.php的注册的tag值来设置，相同的tag的接口将汇集在同一个模块下
        security: [['apiKeyAuth' => []], ['appId' => []]], //指定了在哪些接口上应用SecurityScheme中已经定义的安全方案
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",// 或者application/x-www-form-urlencoded
                schema: new OA\Schema(
                    type: 'object',
                    required:['name'],
                    properties: [
                        // 字符串
                        new OA\Property(property: 'name', type: 'string', description:'名称'
                        ),
                        // 字符串
                        new OA\Property(property: 'email', type: 'string', description:'邮件'
                        ),
                        // 整型
                        new OA\Property(property: 'product_num', type: 'integer', description:'产品数量'
                        ),
                        // 数组 phone => [1111, 22222]
                        new OA\Property(property: 'phone', type: 'array', description:'电话', items: new OA\Items(
                            type: 'integer'
                        )),

                        // 一维关联数组(对象) address = ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区'],
                        new OA\Property(property: 'address', type: 'object', description:'居住地址',
                            properties:[
                                // sheng
                                new OA\Property(property: 'sheng', type: 'string', description:'省份'
                                ),
                                // city
                                new OA\Property(property: 'city', type: 'string', description:'城市'
                                ),
                                // area
                                new OA\Property(property: 'area', type: 'string', description:'县/区'
                                ),
                            ]
                        ),

                        // 二维关联数组 addressList => [
                        //      ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区'],
                        //      ['sheng' => '广东省', 'city' => '深圳市'，'area'=>'宝安区']
                        //  ]
                        new OA\Property(property: 'addressList', type: 'array', description:'地址列表', items: new OA\Items(
                            type: 'object',
                            properties:[
                                // sheng
                                new OA\Property(property: 'sheng', type: 'string', description:'省份'
                                ),
                                // city
                                new OA\Property(property: 'city', type: 'string', description:'城市'
                                ),
                                // area
                                new OA\Property(property: 'area', type: 'string', description:'县/区'
                                ),
                            ]
                        )),
                    ]
                )
            )
        )
    )]

    #[OA\Response(
        response: 200,
        description: '操作成功'
    )]

    public function userList(): array
    {
        return [
            'rules' => [
                    'name' => 'required|float|json',
                    'order_ids' => 'required|array',
                    'order_ids.*' => 'int'
            ],

            'messages' => [
                    'name.required' => '名称必须',
                    'name.json' => '名称必须json字符串',
            ]
        ];
    }

    /**
     * @return array[]
     */
    #[OA\Get(
        path: '/user/user-order/userList1',
        summary:'订单列表',
        description:'获取订单列表内容111',
        tags: [Swag::MODULE_TAG_ORDER],// 根据Swag.php的注册的tag值来设置，相同的tag的接口将汇集在同一个模块下
        security: [['apiKeyAuth' => []], ['appId' => []]], //指定了在哪些接口上应用SecurityScheme中已经定义的安全方案
    )]
    // Get Query
    #[OA\QueryParameter(name: 'order_id', description: "订单ID", required: true, allowEmptyValue: false, allowReserved: true, schema: new OA\Schema(type:'integer')
    )]
    // Get Query
    #[OA\QueryParameter(name: 'product_name', description: '产品名称', required: true, allowEmptyValue: true, allowReserved: true, schema: new OA\Schema(type:'string')
    )]

    // Get Query array eg: ids[1]=22&ids[2]=333
    #[OA\QueryParameter(name: 'product_ids', description: '产品名称', required: true, allowEmptyValue: true, allowReserved: true, schema: new OA\Schema(
        type:'array',
        items: new OA\Items(
            type:'integer'
        )
    ))]
    #[OA\Response(
        response: 200,
        description: '操作成功'
    )]
    public function userList1(): array
    {
        return [
            'rules' => [
               
            ],

            'messages' => [
            ]
        ];
    }
}

```


### License
MIT   
Copyright (c) 2017-2025 zengbing huang    
