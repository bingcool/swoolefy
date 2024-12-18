#!/bin/sh

APP_NAME="Test"
ENTRY_FILE="/home/wwwroot/swoolefy/cli.php"
PHP_BIN_FILE="/usr/bin/php"
START_SCRIPT="${PHP_BIN_FILE} ${ENTRY_FILE} start ${APP_NAME} --daemon=1"
STOP_SCRIPT="${PHP_BIN_FILE} ${ENTRY_FILE} stop ${APP_NAME} --force=1"
SlEEP_TIME=10

if [ ! -f ${ENTRY_FILE} ]; then
    echo "cli.php not found"
    exit 1
fi

start_server() {
    echo 'Starting server'
    ${START_SCRIPT}
}

stop_server() {
    echo 'Stopping server'
    ${STOP_SCRIPT}
    sleep ${SlEEP_TIME}
    exit 0
}

trap 'stop_server' TERM INT HUP QUIT
start_server

while true; do
    sleep 10
done