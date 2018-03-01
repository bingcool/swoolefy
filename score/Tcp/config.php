<?php
// httpserver的配置
return [
	'application_index' => 'App\\Application',
	'start_init' => 'Swoolefy\\Tcp\\StartInit',
	'master_process_name' => 'php-tcp-master',
	'manager_process_name' => 'php-tcp-manager',
	'worker_process_name' => 'php-tcp-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9503',
	// websocket独有
	'accept_http' => true,
	'time_zone' => 'PRC', 
	'setting' => [
		'dispatch_mode' => 3
	],
	'table_tick_task' => true,
	'table' => [
		'table1' => [
			'size' => 1024,
			'fields'=> [
				['tick_tasks','string',512]
			]
		],
		'table2' => [
			'size' => 1024,
			'fields'=> [
				['after_tasks','string',512]
			]
		],
	],
];