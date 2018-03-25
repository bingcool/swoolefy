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
				 // 数据库类型
			    'type'            => 'mysql',
			    // 服务器地址
			    'hostname'        => '192.168.99.102',
			    // 数据库名
			    'database'        => 'bingcool',
			    // 用户名
			    'username'        => 'swoole',
			    // 密码
			    'password'        => '123456',
			    // 端口
			    'hostport'        => '3306',
			    // 连接dsn
			    // 'dsn'             => '',
			    // 数据库连接参数
			    // 'params'          => [],
			    // 数据库编码默认采用utf8
			    'charset'         => 'utf8',
			    // 数据库表前缀
			    // 'prefix'          => '',
			    // 数据库调试模式
			    'debug'           => true,
			    // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
			    // 'deploy'          => 0,
			    // 数据库读写是否分离 主从式有效
			    // 'rw_separate'     => false,
			    // 读写分离后 主服务器数量
			    // 'master_num'      => 1,
			    // 指定从服务器序号
			    // 'slave_no'        => '',
			    // 是否严格检查字段是否存在
			    // 'fields_strict'   => true,
			    // 数据集返回类型
			    // 'resultset_type'  => '',
			    // 自动写入时间戳字段
			    // 'auto_timestamp'  => false,
			    // 时间字段取出后的默认时间格式
			    // 'datetime_format' => 'Y-m-d H:i:s',
			    // 是否需要进行SQL性能分析
			    // 'sql_explain'     => false,
			    // Builder类
			    // 'builder'         => '',
			    // Query类
			    // 'query'           => '\\think\\db\\Query',
			    // 是否需要断线重连
			    'break_reconnect' => true,
        	],
        	'cache_driver'=>'redis',
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