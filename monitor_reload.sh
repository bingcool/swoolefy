#!/bin/bash
# docker容器里面直接改动的文件可以触发文件事件，在宿主机外面挂载卷改动的文件无法触发文件事件，可以用在test环境，更新代码后自动重启
# alpine需安装: apk add inotify-tools

# 应用名称,启动时通过第一个参数传进来eg:
# 当前终端启动：./monitor_reload Test
# 后台进程启动 nohup ./monitor_reload.sh Test >> /dev/null 2>&1 &

appName="$1"

# 通过进程名称查找进程ID,并排除当前的进程
# monitor_reload.sh 终端进程，并排除当前的进程：grep -v $$
ps aux | grep monitor_reload.sh | grep -v grep | awk '{print $1}' | grep -v $$ | xargs kill -15
# inotifywait 监控文件事件进程
ps aux | grep inotifywait | grep -v grep | awk '{print $1}'| xargs kill -15

# 启动进程
basepath=$(cd `dirname $0`; pwd)
cd $basepath

# 先停止
/usr/bin/php cli.php stop $appName --force=1
# 守护进程模式启动
/usr/bin/php cli.php start $appName --daemon=1

while true; do
   file_changes=$(inotifywait -r -e modify,create,delete "$basepath" --format '%w%f')
   php_files=$(echo "$file_changes" | grep -E '\.php$')
   if [ -n "$php_files" ]; then
        echo "PHP files modified:$php_files"
        sleep 10;
        # 先停止
        /usr/bin/php cli.php stop $appName --force=1
        # 守护进程模式启动
        /usr/bin/php cli.php start $appName --daemon=1
   else
        env_files=$(echo "$file_changes" | grep -E '\.env$')
            if [ -n "$env_files" ]; then
                echo "Env files modified:$env_files"
                sleep 10;
                # 先停止
                /usr/bin/php cli.php stop $appName --force=1
                # 守护进程模式启动
                /usr/bin/php cli.php start $appName --daemon=1
            fi
   fi

done


