#!/bin/bash
# docker容器里面直接改动的文件可以触发文件事件，在宿主机外面挂载卷改动的文件无法触发文件事件，可以用在test环境，更新代码后自动重启
# alpine需安装: apk add inotify-tools

# 应用名称,启动时通过第一个参数传进来eg:
# 当前终端启动：./monitor_reload Test
# 后台进程启动 nohup ./monitor_reload.sh Test >> /dev/null 2>&1 &

appName="$1"

portCli=9501
portDaemon=9602
portCron=9603

# 通过进程名称查找进程ID,并排除当前的进程
# monitor_reload.sh 终端进程，并排除当前的进程：grep -v $$
ps aux | grep monitor_reload.sh | grep -v grep | awk '{print $1}' | grep -v $$ | xargs kill -15
# inotifywait 监控文件事件进程
ps aux | grep inotifywait | grep -v grep | awk '{print $1}'| xargs kill -15

# 启动进程
basepath=$(cd `dirname $0`; pwd)
cd $basepath

# 检测端口是否是否被占用
portUsingFun() {
  local port="$1"
  listen=$(netstat -an | grep "$port" | awk '{print $NF}')
  if [ "$listen" = "LISTEN" ]; then
    echo 1
  else
    echo 0
  fi
}

while true; do
   file_changes=$(inotifywait -r -e modify,create,delete "$basepath" --format '%w%f')
   php_files=$(echo "$file_changes" | grep -E '\.php$')
    if [ -n "$php_files" ]; then
        for execBinFile in cli.php daemon.php cron.php;do
            echo "PHP files modified:$php_files"
            if [ "$execBinFile" = "cli.php" ]; then
                sleep 10;
            else
                sleep 1;
            fi
            # 先停止
            /usr/bin/php $execBinFile stop $appName --force=1

            if [ "$execBinFile" = "cli.php" ]; then
                while true; do
                  listen=$(portUsingFun $portCli)
                  if [ "$listen" = 0 ]; then
                    echo "端口=$portCli 不被占用,可以重启"
                    break
                  fi
                  sleep 1
                done
            fi

            if [ "$execBinFile" = "daemon.php" ]; then
                while true; do
                  listen=$(portUsingFun $portDaemon)
                  if [ "$listen" = 0 ]; then
                    echo "端口=$portDaemon 不被占用,可以重启"
                    break
                  fi
                  sleep 1
                done
            fi

            if [ "$execBinFile" = "cron.php" ]; then
                while true; do
                  listen=$(portUsingFun $portCron)
                  if [ "$listen" = 0 ]; then
                    echo "端口=$portCron 不被占用,可以重启"
                    break
                  fi
                  sleep 1
                done
            fi

             # 守护进程模式启动
            /usr/bin/php $execBinFile start $appName --daemon=1
       done
    fi
done


