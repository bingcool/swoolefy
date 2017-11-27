（1）安装xhprof可以参考这个http://www.jianshu.com/p/c420ebe6ce39
如果出现安装了graphviz，依然出现failed to execute cmd：" dot -Tpng"，应该是proc_poen这个函数给禁止了，可以设置disable_function这个
（2）直接在nginx配置location /xhprof_html(.*) {}即可，同时需要在代理设置 proxy_set_header Host $host:$server_port;