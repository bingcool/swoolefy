#!/bin/bash
read PID <<< $(netstat -ntlp | grep $1 | awk '{print $7}' | awk -F '/' '{print $1}')
if [ "${PID}" != "" ]; then
   printf "${PID}"
else 
   printf 0
fi
