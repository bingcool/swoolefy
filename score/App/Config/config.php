<?php
return [
	'route_model' => 1, //1代表pathinfo,2代表普通url模式
	'default_route' => 'site/index',
	'default_namespace' => 'App',
	'not_found_template' => '404.html', //默认是在View文件夹下面
	// 'catch_all_info' => '网站维护中',
	'cors' =>[
		'Origin' => ['*'],
        'Access-Control-Request-Method' => ['GET','POST','PUT','DELETE'],
        'Access-Control-Request-Headers' => ['X-Wsse'],
		'Access-Control-Allow-Credentials' => true,
		'Access-Control-Max-Age' => 86400,
		'Access-Control-Expose-Headers' => ['X-Pagination-Current-Page'],
	],

	'components' => [
		'view' => [
			'class' => 'Swoolefy\Core\View',
		],

		'log' => [
			'class' => 'Swoolefy\Tool\Log',
		],

		'session'=>[

		],

		'mysql' => [
			'config' =>[
				'type'=>'mysql',
				'master_host' =>[],
				'slave_host' =>[],
				'dbname' => '',
				'username' =>'',
				'password' =>'',
				'port' =>'',
				'params' => [], // 数据库连接参数        
	        	'charset' => 'utf8',
	        	'deploy'  => 0 //是否启用分布式的主从
        	]
		],

		'mongodb'=>[

		],

		
		'redis' =>[

		],
	],
];