<?php
return [
	'route_model' => 1, //1代表pathinfo,2代表普通url模式
	'default_route' => 'site/index',
	'app_namespace' => 'App',
	'not_found_template' => '404.html', //默认是在View文件夹下面
	// 'not_found_function' => ['App\Controller\NotFound','page404'],
	// 'catch_all_info' => '网站维护中',
	
	// 由于不是http应用，不需要设置view,session组件
	'components' => [
		// 
		'log' => [
			'class' => 'Swoolefy\Tool\Log',
		],

		'redis_session' => [
			'isDelay' => true,//延迟创建实例，请求时候再创建
			'class' => 'Swoolefy\Core\Cache\Redis',
			'constructor'=> [
				[
					'scheme' => 'tcp',
    				'host'   => '192.168.99.102',
    				'port'   => 6379,
    				'password' => '123456'
				],
				[
					'profile' => '3.2'
				]
			]
		],

		'mailer' =>[
			'class'=> 'Swoolefy\Tool\Swiftmail',
			'smtpTransport' => [
				"server_host"=>"smtp.163.com",
				"port"      =>25,
				"security"  =>null,
				"user_name" =>"13560491950@163.com",
				"pass_word" =>"2xxxxxxxxxxxx",
			],
		],

		'db' => [
			'class' => 'Swoolefy\Core\Db\Mysql',
			'config' =>[
				'type'=>'mysql',
				'master_host' =>['192.168.99.102'],
				'slave_host' =>['192.168.99.102'],
				'dbname' => 'bingcool',
				'username' =>'swoole',
				'password' =>'123456',
				'prefix' => '',
				'port' =>3306,
	        	'charset' => 'utf8',
	        	'deploy'  => 0 //是否启用分布式的主从
        	]
		],

		'mongodb'=>[
			'class'=>'Swoolefy\Core\Mongodb\MongodbModel',
			'database'=>'mytest',
			'uri'=>'mongodb://192.168.99.102:27017',
			'driverOptions'=> [
					'typeMap' => [ 'array' => 'MongoDB\Model\BSONArray', 'document' => 'MongoDB\Model\BSONArray', 'root' => 'MongoDB\Model\BSONArray']
			],
			// '_id' => 'pid'
		],

		'redis' =>[
			'class' => 'Swoolefy\Core\Cache\Redis',
			'constructor'=> [
				[
					'scheme' => 'tcp',
    				'host'   => '192.168.99.102',
    				'port'   => 6379,
    				'password' => '123456'
				],
			]
		],
	],
];