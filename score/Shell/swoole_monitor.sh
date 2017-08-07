#!/bin/bash
read PID <<< $(netstat -ntlp | grep 9501 | awk '{print $7}' | awk -F '/' '{print $1}')
if [ "${PID}" != "" ]; then
   echo $PID
else 
   echo 0
fi
