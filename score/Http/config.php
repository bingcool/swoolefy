<?php
// httpserver的配置
return [
	'application_index' => 'Swoolefy\\App\\Application',
	'start_init' => 'Swoolefy\\Http\\StartInit',
	'master_process_name' => 'php-http-master',
	'manager_process_name' => 'php-http-manager',
	'worker_process_name' => 'php-http-monitor',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9502',
	'time_zone' => 'America/New_York',
	'setting' => [

	]
];