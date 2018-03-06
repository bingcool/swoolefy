# swoolefy
这是一个基于swoole扩展实现的轻量级高性能的API和Web的MVC微服务框架，参考了TP,Yii2,Workerman,swooole-framework等框架的的设计思想,并结合自己的技术积累以及经验。
### 实现的功能特性     
1、轻量级的框架，实现路由与调度，MVC三层，当然也可以配置多层     
2、支持composer和自定义注册命名空间      
3、支持多协议，目前支持http，websocket，tcp，rpc，以及自定义简单协议              
4、利用swoole的原生异步进程封装成应用服务，更有好的任务投放         
5、实现超全局变量，IOC(控制反转)，静态延迟绑定，组件服务常驻内存化，trait的多路复用     
6、简单易用的定时任务,以及table内存表，自定义错误捕捉       
7、灵活多层的配置,配置参数即可实现底层已封装的复杂功能          
8、应用对象的深度复制，实现对象的常驻内存，每个请求只需要从内存中复制应用对象，不需要再重新创建，减少IO消耗，保持内存稳定     
9、封装View，Log，Mysql，Redis，Mongodb，Swiftmail，Session等常用组件，其他组件根据业务按照约定即可封装成组件     
10、支持udp，tcp，http多种方式接入graylog    
11、基于inotify实现自动监测swoole服务的文件变动，实现自动重载，检测，智能邮件通知的服务      
12、封装启动停止控制的脚本，简单命令即可管理整个框架    

### 文档手册将在后期整理     

### 配置环境
#### 安装实际环境(建议)
1、支持php7.0+       
2、搭建lnmp环境，建议使用lnmp一健安装包，https://lnmp.org, 建议安装lnmp1.4     
3、安装php必要的扩展，本框架需要的扩展包括swoole(1.9.17+), swoole_serialize(https://github.com/swoole/swoole_serialize), inotify, pcntl, posix, zlib, mbstring,可以通过php-m查看是否安装了这些扩展，如果通过lnmp1.4一健安装包安装的，已经默认安装好这四个pcntl, posix, zlib, mbstring扩展的，只需要在安装swoole和swoole_serialize, inotify即可，具体安装过程参考官方文档
    
##### docker容器已经配置好的php环境(开发测试)
为了方便开发和测试，我打包了一个基于alpine基础镜像搭建的php7.1环境容器bingcool/php2swoole，这个image已经非常小了，已经安装所有的必须扩展，其中swoole是1.10.1版本，可以通过php --ri swoole 查看信息。     
alpine的官网：https://pkgs.alpinelinux.org/packages    

```
docker pull bingcool/php2swoole     
```
已安装的扩展如下：  
```
bz2    
Core    
curl   
date   
fileinfo    
filter    
ftp    
gd    
hash     
imagick    
inotify    
json   
libxml    
mbstring    
mcrypt  
memcached  
mongodb  
mysqlnd   
openssl  
pcntl  
pcre   
PDO   
pdo_mysql  
posix   
readline   
redis   
Reflection   
session   
SimpleXML   
soap    
sockets   
SPL    
standard    
swoole   
swoole_serialize    
xml    
xmlrpc   
Zend OPcache    
zip    
zlib    
[Zend Modules]     
Zend OPcache    
```

### 下载框架和安装
1、如果是自己安装的php环境（需在linux环境下），最好先创建一个不能登录伪用户www，用来执行worker进程业务代码      
```
useradd www -d /home/www -s /sbin/nologin
```
则在某一个web目录，例如/home/www下                     
(1)下载   
```
git clone https://github.com/bingcool/swoolefy.git  
```

(2) 安装依赖 (需要安装composer)
```
composer install  
```

注意，composer install时，可能或提示说要求安装mongodb的扩展才能install,有两种处理方式：     
a) 安装mongodb扩展,然后再执行composer install安装      
b) 可能暂时不需要用到mongodb的，可以删除文件的composer.lock文件和将composer.json的require中的"mongodb/mongodb": "1.2.0"删除或者屏蔽掉，然后再执行composer install安装。如果后期安装好了扩展，需要安装mongodb依赖，再执行   
```
composer require mongodb/mongodb": "1.2.0"
```
   
2、如果是通过bingcool/php2swoole容器启动php开发环境的，同样需要composer install下载整个完整代码，然后复制到缩主机的/home/www/目录下。   下面是简单使用，首先是启动容器      
```   
docker run -it -d --name swoole -p 9502:9502 -v /home/www/:/home/www/ bingcool/php2swoole   
```
-v /home/www/:/home/www/ 是将缩主机的/home/www目录挂载到容器的/home/www  

(1)然后进入容器  
```
docker exec -it swoole /bin/sh
```

### 监控程序   
1、启动文件自动监控程序，进入swoolefy/score/AutoReload     
php  start.php -d  

监控程序自动监控php的文件变动，然后swoole的worker自动重启，这个文件其实是通过调用代码Shell文件夹的swoole_monitor.sh来监控9502端口(这个是swoole的http服务的默认端口)，根据端口监听，可以设置不同端口，监听不同协议服务。  需要注意的是，由于在容器中/home/www的目录是挂载与缩主机的，inotify是无法监听到文件变动的，所以这个监控程序在容器环境中是无效的，每次修改代码必须重启      

### http服务   
2、启动swoole的http服务，进入swoolefy/score/Http       
启动：php start.php start http          
停止：php start.php stop http              

默认端口是9502，可以在配置文件swoolefy/score/Http/config.php中更改，同时对应的swoolefy/score/AutoReload下的daemon.php中对应更改端口，实现不同的自动重载。  
注意文件权限问题

### websocket服务    
1、启动swoole的websocket服务，进入swoolefy/score/Websocket    
启动：php start.php start websocket        
停止：php start.php stop websocket      

默认端口9503，可以在配置文件swoolefy/score/Websocket/config.php中更改     

### tcp服务   
1、启动swoole的tcp服务，进入swoolefy/score/Tcp         
启动：php start.php start tcp    
停止：php start.php stop tcp

默认端口9504，可以在配置文件swoolefy/score/Tcp/config.php中更改  

### 访问test     
在App/Controller中就可以编码测试，基本和thinkphp的mvc那样操作。
比如在App/Controller/TestController.php
那么直接在浏览器输入http://ip:9502/Test/test, 对应的路由规则
```
domain/controller/action 
```
   
如果存在module模块      
```
module/controller/action
```
具体的可以参考App/Controller/的demo

如果需要使用mysql，redis，mongodb这些组件功能，请安装对应的扩展和服务，并在App/Config/config.php中配置。这个与Yii2的Component相似.

### nginx代理      
为了使用更好支持的HTTP协议，建议前端使用nginx作为代理,更多功能可以看proxy模块来设置   
```
location / {
            proxy_http_version 1.1;
            proxy_set_header Connection "keep-alive";
            proxy_set_header X-Real-IP $remote_addr;
            proxy_pass http://127.0.0.1:9502;
        }
```

那么在浏览器输入http://domain/Test/test     

