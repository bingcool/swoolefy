<?php

return [
    // fork task
    [
        'cron_name' => 'send message', // 发送短信
        'cron_expression' => 10, // 10s执行一次
        //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
        'url'   => 'http://127.0.0.1:9501/index/index',
        'method' => 'get',
        'connect_time_out' => 10, //连接对方主机最长等待时间
        'request_time_out' => 15, // 整个请求最长等待总时间，要比connection_time_out大
        'options' => [], // curl option
        'headers' => [], // 请求头
        'params' => [], // post参数
//        'callback' => function(RawResponse $response) {
//            (new \Test\WorkerCron\CurlQuery\RemoteUrl())->handle($response);
//        },
        'before_callback' => function() {
            var_dump('before_callback');
        },
        'response_callback' => [\Test\WorkerCron\CurlQuery\RemoteUrl::class, 'handle'],
        'after_callback' => function() {
            var_dump('after_callback');
        },
    ]
];