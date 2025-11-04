<?php

use Swoolefy\Worker\Cron\CronProcess;

return [
    // 定时请求远程url触发远程url的任务处理
    [
        'process_name' => 'test-url-task-cron', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronUrlProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // 定时任务列表
//            'task_list' => [
//                // fork task
//                [
//                    'cron_name' => 'send message', // 发送短信
//                    'url'   => 'http://www.baidu.com',
//                    'method' => 'get',
//                    'connect_time_out' => 10, //连接对方主机最长等待时间
//                    'curl_time_out' => 15, // 整个请求最长等待总时间，要比connection_time_out大
//                    'options' => [], // curl option
//                    'headers' => [], // 请求头
//                    'params' => [], // post参数
////                    'callback' => function(RawResponse $response) {
////                        (new \Test\WorkerCron\CurlQuery\RemoteUrl())->handle($response);
////                    },
//                    'callback' => [\Test\WorkerCron\CurlQuery\RemoteUrl::class, 'handle'],
//                    'cron_expression' => 10, // 10s执行一次
//                    //'cron_expression' => '*/1 * * * *', // 每分钟执行一次
//                ]
//            ],

            // 动态定时任务列表，读取数据库cronaTask配置模式
            'task_list' => function () {
                $list4 = (new \Test\Module\Cron\Service\CronTaskService())->fetchCronTask(CronProcess::EXEC_URL_TYPE, env('CRON_NODE_ID'));
                // 返回taskList
                $taskList = array_merge($list1 ?? [], $list2 ?? [], $list3 ?? [], $list4 ?? []);
                if (!empty($taskList)) {
                    return $taskList;
                } else {
                    return [];
                }
            }
        ],
    ]
];