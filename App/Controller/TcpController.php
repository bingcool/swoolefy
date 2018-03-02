<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Pack;
use Swoolefy\Core\Controller\BController;

class TcpController extends BController {

	public function test() {
		// 打包数据
		$pack = new Pack(Swfy::$server);
		$data = 'swoole是一个优秀的框架';
		$header = ['length'=>'','name'=>'bingcool'];
		$sendData = $pack->enpack($data, $header, Pack::DECODE_JSON);

		$this->tcp_client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
		$this->tcp_client->set(array(
   		'open_length_check'     => true,
    		'package_length_type'   => 'N',
    		'package_length_offset' => 0,       //第N个字节是包长度的值
    		'package_body_offset'   => 34,       //第几个字节开始计算长度
		));

		//注册连接成功回调
		$this->tcp_client->on("connect", function($cli) use($sendData) {
		    $cli->send($sendData); 
		});

		//注册数据接收回调
		$this->tcp_client->on("receive", function($cli, $data) {

			$header = unpack('Nlength/a30name', mb_strcut($data, 0, 34, 'UTF-8'));
			$data = json_decode(mb_strcut($data, 34, null, 'UTF-8'), true);
			var_dump($data);
		});

		//注册连接失败回调
		$this->tcp_client->on("error", function($cli){
		});

		//注册连接关闭回调
		$this->tcp_client->on("close", function($cli){
		});

		//发起连接
		$this->tcp_client->connect('127.0.0.1', 9504, 0.5);
		dump('test');

	}	
}