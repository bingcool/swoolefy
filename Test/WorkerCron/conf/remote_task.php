<?php

return [
    // fork task
    [
        'cron_name' => 'send message', // 发送短信
        'cron_expression' => 10, // 10s执行一次
        'url'   => 'http://www.baidu.com',
        'method' => 'get',
        'connect_time_out' => 10, //连接对方主机最长等待时间
        'curl_time_out' => 15, // 整个请求最长等待总时间，要比connection_time_out大
        'options' => [], // curl option
        'headers' => [], // 请求头
        'params' => [], // post参数
//        'callback' => function(RawResponse $response) {
//            (new \Test\WorkerCron\CurlQuery\RemoteUrl())->handle($response);
//        },
        'callback' => [\Test\WorkerCron\CurlQuery\RemoteUrl::class, 'handle'],
        //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
    ]
];