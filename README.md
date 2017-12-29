# swoolefy
这是一个基于swoole扩展实现的轻量级高性能的API和Web的MVC微服务框架，参考了TP,Yii2,Workerman等框架的的设计思想。
### 实现的功能特性     
1、轻量级的框架,实现路由与调度,MVC三层,当然也可以配置多层     
2、支持composer和自定义注册命名空间      
3、支持多协议，目前支持http,websocket    
3、利用swoole的原生异步进程封装成应用服务，更有好的任务投放         
4、实现超全局变量,IOC(控制反转),静态延迟绑定,组件服务常驻内存化,trait的多路复用     
5、简单易用的定时任务,以及table内存表，自定义错误捕捉       
6、灵活多层的配置,配置参数即可实现底层已封装的复杂功能          
7、应用对象的深度复制，实现对象的常驻内存，每个请求只需要从内存中复制应用对象，不需要再重新创建，减少IO消耗，保持内存稳定     
8、封装View,Log,Mysql,Redis,Mongodb,Swiftmail邮件等常用组件，其他组件根据业务按照约定即可封装成组件     
9、基于inotify实现自动监测swoole服务的文件变动，实现自动重载，检测，智能邮件通知的服务      
10、封装启动停止控制的脚本,简单命令即可管理整个框架,使用文档手册将在后期整理

### 安装
(1)git clone https://github.com/bingcool/swoolefy.git         
(2)composer install



