<?php
// httpserver的配置
return [
	'application_index' => 'App\\Application',
	'start_init' => 'Swoolefy\\Websocket\\StartInit',
	'master_process_name' => 'php-websocket-master',
	'manager_process_name' => 'php-websocket-manager',
	'worker_process_name' => 'php-websocket-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9503',
	// websocket独有
	'accept_http' => true,
	'time_zone' => 'PRC', 
	'setting' => [
		// websocket使用固定的worker，使用2或4
		'dispatch_mode' => 2
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

	// tcp端口配置
	'tcp_port' => 9999,
	'tcp_setting' => [
		// 'open_eof_check' => true, //打开EOF检测
		// 'open_eof_split' => true,
		// 'package_eof' => "\r\n", //设置EOF
		// 'reload_async' => true,
		'open_length_check'     => 1,
	    'package_length_type'   => 'N',
	    'package_length_offset' => 0,       //第N个字节是包长度的值
	    'package_body_offset'   => 34,       //第几个字节开始计算长度
	    'package_max_length'    => 2000000,  //协议最大长度
	],
];