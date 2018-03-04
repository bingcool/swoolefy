<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Pack;
use Swoolefy\Core\Client\Synclient;
use Swoolefy\Core\Controller\BController;

class TcpController extends BController {

	static $tcp_client = null;

	public function test() {
		$pack = new Pack(Swfy::$server);
		$data = 'swoole是一个优秀的框架'.rand(1,100);
		$header = ['length'=>'','name'=>'bingcool'];
		$sendData = $pack->enpack($data, $header, Pack::DECODE_JSON);

		if(self::$tcp_client instanceof \swoole_client) {
			if(self::$tcp_client->isConnected()) {
			}else {
				//强制关闭 
				self::$tcp_client->close(true);
				self::$tcp_client->connect('127.0.0.1', 9504);
			}
			// 发送数据
			self::$tcp_client->send($sendData);

		}else {
			// 打包数据,根据服务端接受协议，例如头部结构体 header_struct = ['length'=>'N','name'=>'a30']
			
			self::$tcp_client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
			// 定义客户端接收数据协议，不一定与服务器端一致，例如返回时头部结构体 header_struct = ['length'=>'N','name'=>'a30']可以这样，这时对应的协议就不一样处理的
			self::$tcp_client->set(array(
	   			'open_length_check'     => true,
	    		'package_length_type'   => 'N',
	    		'package_length_offset' => 30,       //第N个字节是包长度的值
	    		'package_body_offset'   => 34,       //第几个字节开始计算长度
			));

			//注册连接成功回调
			self::$tcp_client->on("connect", function($cli) use($sendData) {
			    $cli->send($sendData); 
			});

			//注册数据接收回调
			self::$tcp_client->on("receive", function($cli, $data) {

				$header = unpack('a30name/Nlength', mb_strcut($data, 0, 34, 'UTF-8'));
				$data = json_decode(mb_strcut($data, 34, null, 'UTF-8'), true);
				var_dump($data);
			});

			//注册连接失败回调
			self::$tcp_client->on("error", function($cli){
			});

			//注册连接关闭回调
			self::$tcp_client->on("close", function($cli) {
				var_dump('TCP SERVER is close!');
			});

			//发起连接
			self::$tcp_client->connect('127.0.0.1', 9504, 0.5);
			
			dump('test');
			
		}
	}

	public function test1() {

		$data = 'swoole是一个优秀的框架'.rand(1,100);
		$header = ['length'=>'','name'=>'bingcool'];
		$sendData = Pack::enpack($data, $header, Pack::DECODE_JSON);

		$client = new \swoole_client(SWOOLE_TCP | SWOOLE_KEEP);

		$client->set(array(
		    'open_length_check'     => true,
		    'package_length_type'   => 'N',
		    'package_length_offset' => 30,       //第N个字节是包长度的值
		    'package_body_offset'   => 34,       //第几个字节开始计算长度
		    'package_max_length'    => 2000000,  //协议最大长度
		));

		if(!$client->connect('127.0.0.1', 9504))
		{
		    exit("connect failed\n");
		}

		if($client->isConnected()) {

		}else {
			//强制关闭 
			$client->close(true);
			$client->connect('127.0.0.1', 9504);
		}

		$client->send($sendData);

		$res = $client->recv();

		$header = unpack('a30name/Nlength', mb_strcut($res, 0, 34, 'UTF-8'));
		$data = json_decode(mb_strcut($res, 34, null, 'UTF-8'), true);

		
		dump($data);

	}

	public function test3() {
		// 客户端pack协议
		$setting = array(
		    'open_length_check'     => true,
		    'package_length_type'   => 'N',
		    'package_length_offset' => 30,       //第N个字节是包长度的值
		    'package_body_offset'   => 34,       //第几个字节开始计算长度
		    'package_max_length'    => 2000000,  //协议最大长度
		);

		// 客户端的pack协议，解析服务端返回的数据
		$client = new Synclient($setting);

		$client->header_struct = ['name'=>'a30','length'=>'N'];

		$client->pack_length_key = 'length';

		$client->serialize_type = 1;

		$client->addServer(['127.0.0.1', 9504]);


		// 发送给服务端，按照服务端的pack协议打包数据,不一定是和客户端pack协议相同的
		$data = 'swoole是一个优秀的框架,很多开发者都使用这个框架'.rand(1,100);
		$header = ['length'=>'','name'=>'bingcool'];

		$send_data = Synclient::enpack($data, $header, $seralize_type = 'json', $heder_struct = ['length'=>'N','name'=>'a30'],'length');

		dump('gggg');

		$client->connect();

		$client->send($send_data);

		$res = $client->recv();

		dump($res);

		dump('test3');

	}

	public function test4() {
		// 客户端pack协议eof方式
		$setting = array(
		    'open_eof_check' => true, //打开EOF检测
		    'open_eof_split' => true, //打开EOF_SPLIT检测
			'package_eof' => "\r\n\r\n", //设置EOF
		);

		// 客户端的pack协议，解析服务端返回的数据
		$client = new Synclient($setting);
		$client->pack_eof = "\r\n\r\n";

		// 添加远程服务器ip
		$client->addServer(['127.0.0.1', 9504]);

		// 打包数据,服务端使用pack_length方式
		// 发送给服务端，按照服务端的pack协议打包数据,不一定是和客户端pack协议相同的
		$data = 'swoole是一个优秀的框架,很多开发者都使用这个框架'.rand(1,100);
		$header = ['length'=>'','name'=>'bingcool'];
		$send_data = Synclient::enpack($data, $header, $seralize_type = 'json', $heder_struct = ['length'=>'N','name'=>'a30'],'length');

		// 打包数据,服务端使用eof方式
		// 发送给服务端，按照服务端的pack协议打包数据,不一定是和客户端pack协议相同的
		// $data = 'swoole是一个优秀的框架,很多开发者都使用这个框架'.rand(1,100);
		// $send_data = $client->enpackeof($data,'json');

		$client->connect();

		$client->send($send_data);

		$res = $client->recv();

		dump($res);
	}
}