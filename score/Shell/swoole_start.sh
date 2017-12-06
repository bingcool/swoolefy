#!/bin/sh

CURRENT_DIR=$(dirname $(pwd))
START_FILE=$CURRENT_DIR"/server.php"

PORT=9501

PHP=/usr/bin/php

start() {
  $PHP $START_FILE
  echo "swoole-server start successful!"
}

stop() {
  read PID <<< $(netstat -ntlp | grep 9501 | awk '{print $7}' | awk -F '/' '{print $1}')
  if [ "${PID}" != "" ];then
     kill ${PID}
  else
     exit
  fi
}

restart() {
   stop
   echo "swoole-server is shutdown now!"
   echo "swoole-server is starting....."
   sleep 5s
   start
}

status() {
  read PID <<< $(netstat -ntlp | grep 9501 | awk '{print $7}' | awk -F '/' '{print $1}')
  echo "pid ${PID} swoole-server is running!"
}
case "$1" in 
   start)
       start && exit 0
   ;;
   stop)
       stop && exit 0
   ;;
   restart)
   restart && exit 0
   ;;
   status)
      status && exit 0
   ;;
  *)
   echo $"Usage: $0 {start|stop|status|reload}"
        exit 2
esac

