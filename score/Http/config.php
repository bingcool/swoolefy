<?php
// httpserver的配置
return [
	'application_index' => 'Swoolefy\\App\\Application',
	'start_init' => 'Swoolefy\\Http\\StartInit',
	'master_process_name' => 'php-http-master',
	'manager_process_name' => 'php-http-manager',
	'worker_process_name' => 'php-http-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9502',
	'time_zone' => 'America/New_York',
	'include_files' =>[],
	'setting' => [
		'dispatch_mode' => 3,
		'reload_async' => true,
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