# swoolefy
swoolefy是一个基于swoole实现的轻量级高性能的常驻内存型的API和Web应用服务框架，
高度封装了http，websocket，udp服务器，以及基于tcp实现可扩展的rpc服务，
同时支持composer包方式安装部署项目。基于实用，swoolefy抽象Event事件处理类，
实现与底层的回调的解耦，支持协程调度，同步|异步调用，全局事件注册，心跳检查，异步任务，多进程(池)等，
内置view、log、session、mysql、redis、mongodb等常用组件等。     

目前swoolefy4.0+版本完全支持swoole4.x的协程，推荐使用swoole4.2.x.

### 实现的功能特性     
1、轻量级高性能的框架，实现路由与调度，MVC三层，当然也可以配置多层   
2、支持composer的PSR-4规范和实现自定义注册命名空间，快速部署项目，简单易用      
3、支持多协议，目前支持http，websocket，tcp，udp，以及基于tcp实现的rpc，开放式的系统接口，可自定义协议数据格式    
4、抽象Event的事件处理与底层的事件监听解耦，屏蔽不同协议之间的应用差异，大部分代码实现共用   
5、实现超全局变量，IOC，静态延迟绑定，组件服务常驻内存化，协程对象池，trait的多路复用，钩子事件，单例，工厂模式，注册树模式等   
6、简单易用的异步务管理TaskManager， 定时器管理TickManager， 内存表管理TableManager， 自定义进程管理ProcessManager，进程池管理PoolsManger，超全局管理                    
7、支持rpc服务单实例注册，实现RPC心跳检查，RPC客户端，封包解包    
8、封装View，Log，Mysql，Redis，Mongodb，Swiftmail，Session等常用组件，其他组件根据业务按照约定即可封装成组件                  
9、实现异步半阻塞与全异步非阻塞，协程与非协程，EventHander与底层解耦，注册应用实例      
10、支持swoole4.x的原生协程，高度封装mysql，redis协程组件，同时支持mysql，redis连接池。  
11、支持自定义进程的redis，rabitmq，kafka的订阅发布，消息队列，支持crontab  
12、支持定时的系统信息采集，并以订阅发布，udp等方式收集     
13、基于inotify实现自动监控swoole服务的文件变动，实现worker自动reload，智能邮件通知    
14、命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架           

### 开发文档手册

文档:[开发文档](https://www.kancloud.cn/bingcoolhuang/php-swoole-swoolefy/587501)     
swoolefy官方QQ群：735672669，欢迎加入！    

### License
MIT
