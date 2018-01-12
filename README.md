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
9、支持udp,tcp,http多种方式接入graylog    
10、基于inotify实现自动监测swoole服务的文件变动，实现自动重载，检测，智能邮件通知的服务      
11、封装启动停止控制的脚本,简单命令即可管理整个框架,使用文档手册将在后期整理

### 配置环境
1、支持php7.0+       
2、搭建lnmp环境，建议使用lnmp一健安装包，https://lnmp.org, 建议安装lnmp1.4     
3、安装php必要的扩展，本框架需要的扩展包括swoole(1.9.17+), swoole_serialize(https://github.com/swoole/swoole_serialize), inotify, pcntl, posix, zlib, mbstring,可以通过php-m查看是否安装了这些扩展，如果通过lnmp1.4一健安装包安装的，已经默认安装好这四个pcntl, posix, zlib, mbstring扩展的，只需要在安装swoole和swoole_serialize, inotify即可，具体安装过程参考官方文档

### 下载框架和安装
在某一个web目录下                   
(1)git clone https://github.com/bingcool/swoolefy.git         
(2)composer install(需要安装composer)

### 启动
1、启动文件自动监控程序，进入swoolefy/score/AutoReload     
php  start.php -d            

监控程序自动监控php的文件变动，然后swoole的worker自动重启，这个文件其实是通过调用代码Shell文件夹的swoole_monitor.sh来监控9502端口(这个是swoole的http服务的默认端口)           

2、启动swoole的http服务，进入swoole/score/Http       
启动：php start.php start http          
停止：php start.php stop http              

默认端口是9502，当然可以在配置文件中更改,同时对应的swoolefy/score/AutoReload下的daemon.php中对应更改。
注意文件权限问题

### 访问test
在App/Controller中就可以编码测试，基本和thinkphp的mvc那样操作。
比如在App/Controller/TestController.php
那么直接在浏览器输入http://ip:9502/Test/test, 对应的路由规则domain/controller/action
具体的可以参考App/Controller/的例子

如果需要使用mysql，redis，mongodb这些组件功能，请安装并在App/Config/config.php中配置。这个与Yii2的Component相似.



