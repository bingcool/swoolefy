<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;

class UdpController extends BController {

	public function udptest() {
		$service = 'Service/Coms/Udp/LogService';
		$event = 'saveLog';
		$message =  ["protocol"=>"UDP","name"=>str_repeat('abcd',15), "age"=>'bingcool'.rand(1,1000)];

		$data = $service."::".$event."::".json_encode($message);

        $client = new \Swoole\Client(SWOOLE_SOCK_UDP);
        $client->connect('127.0.0.1', 9505, 1);
       
        $client->send($data);

	}

	public function task() {
		
	}
}