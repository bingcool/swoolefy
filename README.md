# swoolefy
swoolefy是一个基于swoole扩展实现的轻量级高性能的常驻内存型的API和Web应用服务框架。swoolefy的设计思想汲取了Tp5,Yii2,workerman,swooole-framework,easyswoole等框架的优秀设计思想，并结合本人多年的技术积累以及实践经验，它一定不是最优秀的框架，但是这是一个适合学习swoole的框架，特别适合中小团队，用过Tp的phper基本都会用，有过Yii2的经验者，那就更容易入手了。      
swoolefy底层屏蔽了swoole与传统php-fpm的一些差异，让大部分的phper使用起来可以像使用php-fpm那样，轻松入手
### 实现的功能特性     
1、轻量级的框架，实现路由与调度，MVC三层，当然也可以配置多层   
2、支持composer的PSR4规范和实现自定义注册命名空间    
3、支持多协议，目前支持http，websocket，tcp，udp，以及基于tcp实现的rpc,开放式的系统接口,可自定义协议数据格式    
4、抽象Event的事件处理与底层的事件监听解耦，屏蔽不同协议之间的应用差异，大部分代码实现共用   
5、实现超全局变量，IOC，静态延迟绑定，组件服务常驻内存化，trait的多路复用，钩子事件，单例,工厂模式等   
6、简单易用的异步务管理TaskManager， 定时器管理TickManager， 内存表管理TableManager， 进程管理ProcessManager，超全局管理    
7、灵活多层的配置,配置参数即可实现底层已封装的复杂功能    
8、应用对象的深度复制，实现对象的常驻内存，每个请求只需要从内存中复制应用对象，不需要再重新创建，减少IO消耗，保持内存稳定    
9、封装View，Log，Mysql，Redis，Mongodb，Swiftmail，Session等常用组件，其他组件根据业务按照约定即可封装成组件         
10、实现异步半阻塞与全异步非阻塞，EventHander与底层解耦     
11、基于inotify实现自动监控swoole服务的文件变动，实现worker自动reload，智能邮件通知     
12、命令行形式高度封装启动|停止控制的脚本，简单命令即可管理整个框架      

### 开发文档手册
[开发文档](https://www.kancloud.cn/bingcoolhuang/php-swoole-swoolefy/587501)     
[demo(未完整)](https://github.com/bingcool/project)  

swoolefy官方QQ群：735672669，欢迎加入！    

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

### 开发部署
1、如果是自己安装的php环境（需在linux环境下），最好先创建一个不能登录伪用户www，用来执行worker进程业务代码      
```
useradd www -d /home/www -s /sbin/nologin
```
则在某一个web目录，例如/home/www下                     
[参考](https://www.kancloud.cn/bingcoolhuang/php-swoole-swoolefy/587504)   

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
1、启动文件自动监控程序，进入swoolefy   
```   
当前终端启动：php swoolefy start monitor    
守护进程启动：php swoolefy start monitor -d         
停止：php swoolefy stop monitor      
```
可以在配置文件swoolefy/protocol/monitor/config.php设置。监控程序自动监控php的文件变动，然后swoole的worker自动重启，这个文件其实是通过调用代码Shell文件夹的swoole_monitor.sh来监控9502端口(这个是swoole的http服务的默认端口)，根据端口监听，可以设置不同端口，监听不同协议服务。   
  需要注意的是，由于在容器中/home/www的目录是挂载与缩主机的，inotify是无法监听到文件变动的，所以这个监控程序在容器环境中是无效的，每次修改代码必须重启      
### http服务   
2、启动swoole的http服务，进入swoolefy 
```     
启动：php swoolefy start http          
停止：php swoolefy stop http 
```
默认端口是9502，可以在配置文件swoolefy/protocol/http/config.php中更改，同时对应的swoolefy/protocol/monitor/config.php中对应更改端口，实现不同的自动重载。  
注意文件权限问题

### websocket服务    
1、启动swoole的websocket服务，进入swoolefy
```    
启动：php swoolefy start websocket        
停止：php swoolefy stop websocket      
```
默认端口9503，可以在配置文件swoolefy/protocol/websocket/config.php中更改     

### rpc服务   
1、启动swoole的rpc服务，进入swoolefy
```    
启动：php swoolefy start rpc    
停止：php swoolefy stop rpc
```
默认端口9504，可以在配置文件swoolefy/protocol/rpc/config.php中更改。

### udp服务   
1、启动swoole的rpc服务，进入swoolefy
```    
启动：php swoolefy start udp    
停止：php swoolefy stop udp
```
默认端口9505，可以在配置文件swoolefy/protocol/udp/config.php中更改。    

### 访问Index     
在App/Controller中就可以编码测试，基本和thinkphp的mvc那样操作。
比如在App/Controller/IndexController.php
```
<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class IndexController extends BController {

    public function index() {
        $this->response->end('hello word!');
    }

}
```

那么直接在浏览器输入http://ip:9502/Index/index     
若需要渲染模板
```
<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;

class IndexController extends BController {

    public function index() {
        $this->assign('name','hello word!');
        $this->display('index.html');

}
```
对应的路由规则:    
```
controller/action 
```
如果存在module模块:          
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

### License
MIT