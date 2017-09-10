<?php

// class http {
// 	// 
// 	public function __construct() {

// 	}

// 	public function start() {
// 		$http = new swoole_http_server("0.0.0.0", 9501);
// 		$http->set([
// 			'daemonize'=>0,
// 		    'pid_file' => __DIR__.'/server.pid',
// 		]);

// 		$http->on('request', function ($request, $response) {
// 		    $response->header("Content-Type", "text/html; charset=utf-8");
// 		    $response->end("<h1>Hello Swoole. #".rand(1000, 9999)."</h1>");
// 		});

// 		$http->start();
// 	}
// }

// $http = new http();
// $pid = posix_getpid();
// var_dump($pid);

// $http->start();

$http = new swoole_http_server("0.0.0.0", 9501);
$http->set([
	'daemonize'=>0,
    'pid_file' => __DIR__.'/server.pid',
]);
$http->on('WorkerStart',function(swoole_http_server $server, $worker_id) {
	var_dump(get_included_files());
});
$http->on('request', function ($request, $response) {
    $response->header("Content-Type", "text/html; charset=utf-8");
    $response->end("<h1>Hello Swoolennnnnmmmmmmmmmmmmm #".rand(1000, 9999)."</h1>");
});


$http->start();


