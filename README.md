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
高度支持httpApi，websocket，udp服务器，以及基于tcp实现可扩展的rpc服务，
同时支持composer包方式安装部署项目。基于实用，swoolefy抽象Event事件处理类，
实现与底层的回调的解耦，支持协程调度，同步|异步调用，全局事件注册，心跳检查，异步任务，多进程(池)，连接池等，
内置view、log、session、mysql、redis、mongodb等常用组件等。     

主分支：master分支最低要求php8.0+，swoole5.0+（或者swoole-cli-v5.0+）, 或者也可以使用swoole-cli4.8+, 因为其内置php8.1+  

LTS分支：swoolefy-4.8-lts 长期维护，最低要求php >= php7.2 && php < php8.0, 推荐直接swoole-v4.8+，需要通过源码编译安装

选择哪个分支？
1、如果确定项目是使用php8+的，那么直接选择 swoole-v5.0+ 以上版本来编译安装或者直接使用swoole-cli-v5.0，然后选择 swooolefy-v5.0+ 作为项目分支

2、如果确定项目是使用php7.2-php7.4的，那么选择 swoole-v4.8+ 版本来进行编译安装(不能直接使用 swoole-cli-v4.8+ 了, 因为其内置的是php8.1，与你的项目的php7不符合)
所有只能通过编译方式方式来生成swoole扩展，然后选择 swoolefy-4.8-lts 作为项目分支

### 实现的功能特性
- [x] 架手脚一键创建项目           
- [x] 路由与调度，MVC三层，多级配置      
- [x] 支持composer的PSR-4规范，实现PSR-3的日志接口     
- [x] 支持自定义注册不同根命名空间，快速多项目部署          
- [x] 支持httpServer，实用轻量Api接口开发     
- [x] 支持websocketServer,udpServer,mqttServer      
- [x] 支持基于tcp实现的rpc服务，开放式的系统接口，可自定义协议数据格式，并提供rpc-client协程组件
- [x] 支持DI容器，组件IOC、配置化        
- [x] 支持协程单例注册,协程上下文变量寄存    
- [x] 支持mysql、postgreSql协程组件、redis协程组件、mongodb组件     
- [x] 支持mysql的协程连接池，redis协程池
- [x] 支持protobuf buffer的数据接口结构验证，压缩传输等        
- [x] 异步务管理TaskManager，定时器管理TickManager，内存表管理TableManager  
- [x] 自定义进程管理ProcessManager，进程池管理PoolsManger
- [x] 支持底层异常错误的所有日志捕捉,支持全局日志,包括debug、info、notice、warning、error等级       
- [x] 支持自定义进程的redis，rabitmq，kafka的订阅发布，消息队列等     
- [x] 支持crontab计划任务                    
- [x] 支持热更新reload worker                  
- [x] 支持定时的系统信息采集，并以订阅发布，udp等方式收集至存贮端    
- [x] 命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架   
- [x] 支持crontab的local和fork计划任务   
- [x] 支持worker的daemon模式的进程消费模型   
- [x] 支持跑console一次性脚本模式，跑完脚本自动退出，主要用于修复数据等   
- [ ] 分布式服务注册（zk，etcd）       

### 常用组件
| 组件名称 | 安装 | 说明 |
| ------ | ------ | ------ |
| predis | composer require predis/predis:~1.1.7 | predis组件、或者Phpredis扩展 |
| mongodb | composer require mongodb/mongodb:~1.3 | mongodb组件，需要使用mongodb必须安装此组件 |
| rpc-client | composer require bingcool/rpc-client:dev-master | swoolefy的rpc客户端组件，当与rpc服务端通信时，需要安装此组件，支持在php-fpm中使用 |
| cron-expression | composer require dragonmantank/cron-expression | crontab计划任务组件，类似Linux的crobtab |  
| redis lock | composer require malkusch/lock | Redis锁组件 |    
| validate | composer require vlucas/valitron | validate数据校验组件 |  
| bingcool/library | composer require bingcool/library | library组件库 |  

### bingcool/library 是swoolefy require 内置库，专为swoole协程实现的组件库        
实现了包括：    
- [x] Db Mysql Model组件
- [x] PostgreSql Model组件    
- [x] Kafka Producer Consumer组件    
- [x] Redis Cache组件  
- [x] Redis Queue队列组件   
- [x] Redis Delay Queue延迟队列组件            
- [x] RedisLock锁组件   
- [x] RateLimit限流组件   
- [x] Redis Public Subscribe组件    
- [x] Db 、Redis、Curl协程连接池组件
- [x] UUid 自增id组件  
- [x] Curl基础组件    
   
github: https://github.com/bingcool/library    



### 关联项目
bingcool/workerfy 是基于swoole实现的多进程协程模型，专处理daemon后台进程处理      
github: https://github.com/bingcool/workerfy   

### 一、安装 

```
// 下载代码到到你的自定义目录，这里定义为myproject
composer create-project bingcool/swoolefy:^4.8.* myproject
```

### 二、添加项目入口启动文件,并定义你的项目目录，命名为App

```
// 在myproject目录下添加cli.php, 这个是启动项目的入口文件
<?php
include './vendor/autoload.php';

define('IS_WORKER_SERVICE', 0);
date_default_timezone_set('Asia/Shanghai');

define('APP_NAMES', [
     // 你的项目命名为App，对应协议为http协议服务器，支持多个项目的，只需要在这里添加好项目名称与对应的协议即可
    'App' => 'http' 
]);

include './swoolefy';

```

### 三、执行创建你定义的App项目
```
// 你定义的项目目录是App, 在myproject目录下执行下面命令行

swoole-cli cli.php create App

// 执行完上面命令行后，将会自动生成App项目目录以及内部子目录

```

### 四、启动项目

```
// 终端启动 ctl+c 停止进程
swoole-cli cli.php start App

// 守护进程方式启动,添加-D参数控制
swooole-cli cli.php start App -D

// 停止进程
swooole-cli cli.php stop App

// 查看进程状态
swooole-cli cli.php status App

```

### 五、访问

默认端口是9502,可以通过http://localhost:9502访问默认控制器

至此一个最简单的http的服务就创建完成了，更多例子请参考项目下Test的demo


### 定义组件
开放式组件接口，闭包回调实现创建组件过程，return对象即可
```

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

// 在应用层配置文件中,例如下面使用library的Redis、Db组件

components => [
    // 例如创建phpredis扩展连接实例
    'redis' => function($com_name) { // 定义组件名，闭包回调实现创建组件过程，return对象即可
         $redis = new \Common\Library\Cache\Redis();
         $redis->connect('127.0.0.1', 6379);
         $redis->auth('123456789');
         return $redis;   
    },

    // predis组件的redis实例
    'predis' => function($name) {
        $parameters = [
            'scheme' => 'tcp',
            'host'   => '127.0.0.1',
            'port'   => 6379,
            'password' => '123456789'
        ];
        $predis = new \Common\Library\Cache\Predis();
        $predis->setConfig($parameters);
        return $predis;
    },

     // 适配swoole的mysql客户端组件
    'db' => function() {
        $config = [
            // 地址
            'hostname'        => '127.0.0.1',
            // 数据库
            'database'        => 'bingcool',
            // 用户名
            'username'        => 'bingcool',
            // 密码
            'password'        => '123456789',
            // 端口
            'hostport'        => '3306',
            // dsn
            'dsn'             => '',
            // 数据库连接参数
            'params'          => [],
            // 数据库编码,默认采用utf8
            'charset'         => 'utf8',
            // 数据库表前缀
            'prefix'          => '',
            // 是否断线重连
            'break_reconnect' => true,
            // 是否支持事务嵌套
            'support_savepoint' => false
        ];

        $db = new \Common\Library\Db\Mysql($config);
        return $db;
    },
       
    // 适配swoole的postgreSql客户端组件
    'pg' => function() {
        $config = [
            // 地址
            'hostname'        => '127.0.0.1',
            // 数据库
            'database'        => 'dbtest',
            // 用户
            'username'        => 'bingcool',
            // 密码
            'password'        => '123456789',
            // 端口
            'hostport'        => '5432',
            // dsn
            'dsn'             => '',
            // 数据库连接参数
            'params'          => [],
            // 数据库编码,默认采用utf8
            'charset'         => 'utf8',
            // 数据库表前缀
            'prefix'          => '',
            // 是否断线重连
            'break_reconnect' => true,
            // 是否支持事务嵌套
            'support_savepoint' => false
        ];

        $pg = new \Common\Library\Db\Pgsql($config);
        return $pg;
    },

    // 其他的组件都可以通过闭包回调创建
    // 数组配置型log组件
    'log' => [
        'class' => \Swoolefy\Util\Log::class,
        'channel' => 'application',
        'logFilePath' => rtrim(LOG_PATH,'/').'/runtime.log'
    ],
    // 或者log组件利用闭包回调创建
    'log' => function($name) {
        $channel= 'application';
        $logFilePath = rtrim(LOG_PATH,'/').'/runtime.log';
        $log = new \Swoolefy\Util\Log($channel, $logFilePath);
        return $log;
    },
]

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
        //或者
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

        //查询
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

### License
MIT   
Copyright (c) 2017-2022 zengbing huang    
