<?php
namespace App\Controller;

use Swoolefy\Core\Application;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\MGeneral;

class LogController extends BController {

    public function test() {
        $client = new \Swoole\Client(SWOOLE_SOCK_UDP);
        $client->connect('192.168.44.128', 5555, 1);
        dump($client);
        // dump($client->isConnected());
        $msg = [
            "version"=>"1.1", 
            "host"=>$_SERVER['HTTP_HOST'],
            "short_message"=>"Swoole虽然是标准的PHP扩展，实际上与普通的扩展不同。普通的扩展只是提供一个库函数。而swoole扩展在运行后会接管PHP的控制权，进入事件循环。当IO事件发生后，swoole会自动回调指定的PHP函数", 
            "level"=>5, 
            "_some_info"=>"foo"
        ];
        dump(\json_encode($msg));
        $client->send($msg);
        $client->close();
    }

    public function test1() {
        $message =  ["protocol"=>"UDP","name"=>str_repeat('abcd',15000), "age"=>'bingcool'.rand(1,1000)];
        $message = var_export($message, true);
        $client = new \Swoole\Client(SWOOLE_SOCK_UDP);
        $msg = [
            "version"=>"1.1", 
            "host"=>$_SERVER['HTTP_HOST'],
            "short_message" => $message,
            "level"=>5, 
            "_some_info"=>"foo",
            "_udp_send"=>'yes',
            "server_name"=>'test'
        ];

        $data = json_encode($msg)."\n";
        // $client->send($data);

        $client->sendto('192.168.44.128', 5554, $data);
        $client->close();
    }

    public function test2() { 
        $message =  ["protocol"=>"HTTP", "name"=>"huangzengbing", "age"=>27,'namespace'=>__NAMESPACE__.'::'.__FUNCTION__];
        $message = var_export($message, true);
        $msg = [
            "version"=>"1.1", 
            "host"=>$_SERVER['HTTP_HOST'],
            "short_message" =>$message,
            "level"=>5, 
            "_some_info"=>"foo",
            "_http_send"=>'yes',
        ];

        $cli = new \swoole_http_client('192.168.44.128', 12201);
        $cli->setHeaders([
            'Content-Type'=>'application/json',
        ]);
        $cli->post('/gelf', json_encode($msg)."\n", function ($cli) {
            var_dump('llllll');
            var_dump($cli->body);
        });
    }


}