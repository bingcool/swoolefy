<?php

swoole_set_process_name('php-process-worker');

$workers = [];
$worker_num = 10;

function onReceive($pipe) {
    global $workers;
    $worker = $workers[$pipe];
    $data = $worker->read();
    echo "RECV: " . $data;
}

//循环创建进程
for($i = 0; $i < $worker_num; $i++)
{
    
    $process = new swoole_process(function(swoole_process $process) {
        $process->write("Worker#{$process->id}: hello master\n");
        // $process->exit();
    });

    $process->id = $i;
    $pid = $process->start();
    $workers[$process->pipe] = $process;
}

swoole_process::signal(SIGCHLD, function(){
    //表示子进程已关闭，回收它
    while($status = swoole_process::wait(false)) {
        echo "Worker#{$status['pid']} exit\n";
    };
   
});

// swoole_timer_tick(2000, function ($timer_id) {
//     echo "tick-2000ms\n";
// });

//将子进程的管道加入EventLoop
foreach($workers as $process)
{
    swoole_event_add($process->pipe, 'onReceive');
}
