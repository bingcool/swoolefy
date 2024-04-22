#!/bin/bash
# docker容器里面直接改动的文件可以触发文件事件，在宿主机外面挂载卷改动的文件无法触发文件事件，可以用在test环境，更新代码后自动重启
# alpine需安装: apk add inotify-tools

# 应用名称,启动时通过第一个参数传进来eg:
# 当前终端启动：./monitor_reload.sh Test
# 后台进程启动 nohup ./monitor_reload.sh Test >> /dev/null 2>&1 &

appName="$1"

phpBinFile='/usr/bin/php'
scripts=("cli.php")

# 监控目录
basepath=$(cd `dirname $0`; pwd)
cd $basepath

echo "监控目录：$basepath"

while true; do
   file_changes=$(inotifywait -r -e modify,create,delete "$basepath" --format '%w%f')
   php_files=$(echo "$file_changes" | grep -E '\.php$')
    if [ -n "$php_files" ]; then
        for execBinFile in "${scripts[@]}";do
            echo "PHP files modified:$php_files"
            sleep 5;
            #守护进程模式重启动
            nohup $phpBinFile $execBinFile restart $appName --force=1 >> /dev/null 2>&1 &
       done
    fi
done


