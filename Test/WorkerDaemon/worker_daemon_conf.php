<?php
// 分组，方便按照分组方式来部署，尽可能减少重启时对全局进程的影响，同时也可以分配资源在不同的机器上面跑
return [
    'group_1' => array_merge(
        include __DIR__ . '/conf/monitor_conf.php',
        include __DIR__ . '/conf/pipe_conf.php',
    ),
];