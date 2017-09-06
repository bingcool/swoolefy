<?php
// httpserver的配置
return [
	'application_index' => 'Swoolefy\\App\\Application',
	'start_init' => 'Swoolefy\\Http\\StartInit',
	'master_process_name' => 'php-websocket-master',
	'manager_process_name' => 'php-websocket-manager',
	'worker_process_name' => 'php-websocket-monitor',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9503',
	'accept_http' => true,
	'time_zone' => 'PRC', 
	'setting' => [

	]
];