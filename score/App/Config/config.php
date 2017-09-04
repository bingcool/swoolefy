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
	],

	'components' => [
		'view' => [
			'class' => 'Swoolefy\Core\View',
		],
	],
];