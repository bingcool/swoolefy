<?php
return [
	'route_model' => 1, //1代表pathinfo,2代表普通url模式
	'default_route' => 'site/index',
	'app_namespace' => 'App',
	'not_found_template' => '404.html', //默认是在View文件夹下面
	// 'not_found_function' => ['App\Controller\NotFound','page404'],
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

		'db' => [
			'class' => 'Swoolefy\Core\Db\Mysql',
			'config' =>[
				'type'=>'mysql',
				'master_host' =>['localhost'],
				'slave_host' =>['localhost'],
				'dbname' => 'bingcool',
				'username' =>'root',
				'password' =>'root',
				'port' =>3306,
	        	'charset' => 'utf8',
	        	'deploy'  => 0 //是否启用分布式的主从
        	]
		],

		'mongodb'=>[

		],

		'redis' =>[
			'class' => 'Swoolefy\Core\Cache\Redis',
			'constructor'=> [
				'tcp://127.0.0.1:6379',
				[
					'profile' => '3.2'
				]
			]

		],
	],
];