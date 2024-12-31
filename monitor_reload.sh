#!/bin/bash
# docker容器里面直接改动的文件可以触发文件事件，在宿主机外面挂载卷改动的文件无法触发文件事件，可以用在test环境，更新代码后自动重启
# alpine需安装: apk add inotify-tools, apk add jq

# 注意此文件要保存成linux的LF 或者 macos的CR换行，不能是windows的CRLF

# 当前终端启动：/bin/bash monitor_reload.sh
# 后台进程启动 nohup /bin/bash monitor_reload.sh >> /dev/null 2>&1 &

# 监控目录
basepath=$(cd `dirname $0`; pwd)
cd $basepath

echo "监控目录：$basepath"

while true; do
   file_changes=$(inotifywait -e modify,create "$basepath" --format '%w%f')
   log_files=$(echo "$file_changes" | grep -E 'restart.log$')
    if [ -n "$log_files" ]; then
        echo "log files modified:$log_files"
        lastLine=$(tail -n 1 $log_files)
        echo "lastLine:$lastLine"
        command=$(echo "$lastLine" |  jq -r '.command')
        echo "command:$command"
        sleep 1
        #守护进程模式重启动
        if [ "$command" != "null" ]; then
            eval "$command"
        fi
    fi
done