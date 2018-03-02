<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Collection;

class HttpController extends BController {
	public function client() {
		$host = '192.168.99.102';
		$cli = new \swoole_http_client($host, 81);
		$cli->set([
			'keep_alive' => true,
			'timeout' => -1
		]);

	    $cli->setHeaders([
	        'Host' => $host,
	        "User-Agent" => 'Chrome/49.0.2587.3',
	        'Accept' => 'text/html,application/xhtml+xml,application/json',
	        'Accept-Encoding' => 'gzip',
	        'keep_alive' => true,
	    ]);
	    $cli->get('/Test/testajax', function ($cli) {
	        var_dump($cli->body);
	    });

	    dump('kkkk'.rand(1,100));
	    // $res = $cli->close();
	    // dump($res);
	}
	
}