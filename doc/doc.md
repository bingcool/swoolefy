提示：要使用这个框架学习，必须要有掌握swoole基础，了解swoole的工作原理，了解mvc,ioc,超全局变量，静态绑定，特别是内存共享和进程，不然，有些问题永远无法解释的明白。另外只是一个学习的框架，没有任何的组件功能，只是完成了mvc三层的架构，是用来学习swoole和测试swoole的一些功能特性。     
（1）下载目前最新版的swoolefy代码
https://github.com/bingcool/swoolef,
可以看到里面有几个文件夹，App这个是应用层文件夹类似thinkphp的mvc三层，直接在了里面编码，实现业务。score这个目录是框架的核心代码，一般不需要改动的，当然想要学习可能的看里面的代码。     
（2）搭建linux环境和php环境    
要求：    
a、linux版本：由于swoolefy这个框架是使用centos7.0测试开发的，建议安装centos7.0以上版本    
b、php版本：php7.0版本以上     
c、所需php扩展：pcntl，posix，zlib，mbstring，swoole，swoole_serializel     
可以通过php -m 查看是否安装了这些模块，没安装，请自行安装
swoole_serializel：https://github.com/swoole/swoole_serialize可以下载这个安装    
注意：必须自行百度安装以上扩展，否则框架运行会出错，因为核心代码里用到了这些扩展

（3）将swoolefy代码放到一个linux环境的目录下。比如直接放在/usr/local/下
然后给予最高权限，chmod 777 -R /usr/local/swoolefy

(4)启动swoolefy的http服务
必须要用root用户启动，进入swoolefy/score,里面有一个start.php文件
执行：php start.php start http

即可启动http服务，默认端口9502

（5）接着启动脚本自动检测服务，在swoolefy/score/AutoReload下有一个start.php,
执行：php start.php
即可已守护进程启动，它会监听9502端口，所以这个端口是与http的端口相对应的。这样，每次应用层App这个目录下有php文件的变动worker进程就会自动重启，但是对于score/Http下的这些文件，不会自动重启，因为这些文件在worker启动前已经在主进程中加载了，重启只是重启worker进程，不是说整个swoole重启。

（6）访问http://ip:9502/Test/test?name=hello
App这个是我们的应用目录，这个和thinkphp基本一致，上面这个路由对应的就是Test代表Controller,test代表action，对应着App/Controller/TestController/test这个action,简单地说，就是url要访问那个action就直接访问对应的controller/action即可。

