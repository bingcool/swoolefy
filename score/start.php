<?php
include_once "../vendor/autoload.php";

// 创建进程服务实例
$daemon = new Swoolefy\daemon();

$daemon->run();

