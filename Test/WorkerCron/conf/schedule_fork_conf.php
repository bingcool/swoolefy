<?php

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
use Test\Scripts\Kernel;

// 定时fork进程处理任务
return [
        [
        'process_name' => 'system-schedule-task', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
        'description' => '系统fork模式任务调度',
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // 定时任务列表
            //'task_list' => Kernel::buildScheduleTaskList(Kernel::schedule()),

            // 动态定时任务列表，可以存在数据库中
            'task_list' => function () {
                //$list1 = include __DIR__ . '/fork_task.php';
                // $list2 = Kernel::buildScheduleTaskList(Kernel::schedule());

                // 读取yaml文件模式
//                try {
//                    $list3 = array_values(Yaml::parseFile(APP_PATH.'/cron.yaml'));
//                }catch (ParseException $e) {
//                    echo "YAML 解析错误: \n";
//                    echo "文件: " . $e->getParsedFile() . "\n";
//                    echo "行号: " . $e->getParsedLine() . "\n";
//                    echo "详细信息: " . $e->getMessage();
//                }

                // 读取数据库cronTask配置模式
                $list4 = (new \Test\Module\Cron\Service\CronTaskService())->fetchCronTask(1);
                // 返回taskList
                $taskList = array_merge($list1 ?? [], $list2 ?? [], $list3 ?? [], $list4 ?? []);
                if (!empty($taskList)) {
                    return $taskList;
                } else {
                    return [];
                }
            }
        ],
    ],
];