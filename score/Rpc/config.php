<?php
// tcpserver的配置
return [
	'application_index' => 'Service\\Application',
	'start_init' => 'Swoolefy\\Tcp\\StartInit',
	'master_process_name' => 'php-tcp-master',
	'manager_process_name' => 'php-tcp-manager',
	'worker_process_name' => 'php-tcp-worker',
	'www_user' => 'www',
	'host' => '0.0.0.0',
	'port' => '9504',

	'time_zone' => 'PRC', 
	'setting' => [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 1,    //worker process num
		'max_request' => 5,
		'task_worker_num' =>5,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		
		// TCP使用固定的worker，使用2或4
		'dispatch_mode' => 2,

		// 'open_eof_check' => true, //打开EOF检测
		// 'open_eof_split' => true, //打开EOF_SPLIT检测
		// 'package_eof' => "\r\n\r\n", //设置EOF
		
		'open_length_check'     => 1,
    	'package_length_type'   => 'N',
    	'package_length_offset' => 0,       //第N个字节是包长度的值
    	'package_body_offset'   => 34,       //第几个字节开始计算长度
    	'package_max_length'    => 2000000,  //协议最大长度

    	'log_file' => __DIR__.'/log.txt',
		'pid_file' => __DIR__.'/server.pid',

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

	// 长度检查packet时，设置包头结构体
	'packet'=>[
		'pack_header_strct' => ['length'=>'N','name'=>'a30'],
		'pack_length_key' => 'length',
	],
	// 
	'async_hander' => 1,

];