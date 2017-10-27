<?php
namespace App\Controller;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Application;
use Swoolefy\Core\ZModel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\HttpServer;
use Swoolefy\Core\MGeneral;

class TestController extends BController {

	public function __construct() {
		parent::__construct();
	}	

	public function test() {
		// Application::$app->db->test();
		$ip = MGeneral::getClientIP();
		dump($ip);
		$localIp = MGeneral::getClientIP();
		dump($localIp);
		$brower = MGeneral::getBrowser();
		dump($brower);
		$data = $this->getModel()->getTest();
		$this->assign('name',$data['name']);
		$this->display('test.html');
	}

	public function testajax() {
		$res = ['name'=>'bingcool','age'=>26,'sex'=>1,'info'=>['cloth'=>'red','phone'=>'12222']];
		var_dump($res);
	}

	public function testRedirect() {
		self::rememberUrl('mytest','/Test/mytest');
		$this->assign('name','NKLC');
		$url = (parent::getPreviousUrl('mytest'));
		$this->redirect($url);
		return;
	}

	public function mytest() {
		$data = $this->getModel()->getTest();
		return $data;
	}

	/**
	 * asyncHttpClient 异步并发http请求
	 * @param    $urls 
	 * @param    $timeout 单位ms
	 * @author   huangzengbing
	 * @return   
	 */
	public function asyncHttpClient($urls=[],$timeout=500) {
		if(!empty($urls)) {
			$conn = [];
			$mh = curl_multi_init();
			foreach($urls as $i => $url) {
				$conn[$i] = curl_init($url);
					curl_setopt($conn[$i], CURLOPT_CUSTOMREQUEST, "GET");
				  	curl_setopt($conn[$i], CURLOPT_HEADER ,0);
				  	curl_setopt($conn[$i], CURLOPT_SSL_VERIFYPEER, FALSE);
					curl_setopt($conn[$i], CURLOPT_SSL_VERIFYHOST, FALSE);
					curl_setopt($conn[$i], CURLOPT_NOSIGNAL, 1);
					curl_setopt($conn[$i], CURLOPT_TIMEOUT_MS,$timeout);   
				  	curl_setopt($conn[$i],CURLOPT_RETURNTRANSFER,true);
				  	curl_multi_add_handle($mh,$conn[$i]);
			}

			do {   
  				curl_multi_exec($mh,$active);   
			}while ($active);

			foreach ($urls as $i => $url) {   
  				curl_multi_remove_handle($mh,$conn[$i]);   
  				curl_close($conn[$i]);   
			}
			curl_multi_close($mh);
			return;
		}
		return;
	}
}