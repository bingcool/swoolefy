<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;
use Swoolefy\Core\MTime;

class UdpController extends BController {

	public function udptest() {
		$service = 'Service/Coms/Udp/LogService';
		$event = 'saveLog';
		$message =  ["protocol"=>"UDP","name"=>str_repeat('abcd',15), "age"=>'bingcool'.rand(1,1000)];

		$data = [$service, $event, $message];


        $client = new \Swoole\Client(SWOOLE_SOCK_UDP);
        $client->connect('127.0.0.1', 9505, 1);
       
        $data = json_encode($data);
        // $client->send($data);
        $client->send($data);

        dump('hello');

	}
}