<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\Client;

use Swoolefy\Core\Client\RpcSynclient;
use Swoolefy\Core\Client\RpcClientConst;

class RpcClientManager {

	use \Swoolefy\Core\SingletonTrait;

	/**
	 * $client_pack_setting client的包设置
	 * @var array
	 */
	protected static $client_pack_setting = [];

	/**
	 * $client_services 客户端所有注册的服务实例
	 * @var array
	 */
	protected static $client_services = [];

	/**
	 * $busy_client_services 正在工作的服务实例
	 * @var array
	 */
	protected static $busy_client_services = [];

	/**
	 * $is_swoole_env 是在swoole环境中使用，或者在apache|php-fpm中使用
	 * @var boolean
	 */
	protected $is_swoole_env = false;

	/**
	 * __construct 
	 * @param  array $setting
	 */
	protected function __construct(bool $is_swoole_env = false) {
		$this->is_swoole_env = $is_swoole_env;
	}

	/**
	 * registerService 注册服务
	 * @param    string    $serviceName
	 * @param    array     $serviceConfig
	 * @param    array     $setting
	 * @param    array     $header_struct
	 * @param    string    $pack_length_key
	 * @return   object
	 */
	public function registerService(
		string $serviceName, 
		array $serviceConfig = [], 
		array $client_pack_setting = [], 
		array $server_header_struct = [], 
		array $client_header_struct = [], 
		array $args = []
	) {
		$servers = $serviceConfig['servers'];
		$timeout = $serviceConfig['timeout'];
		$noblock = isset($serviceConfig['noblock']) ? $serviceConfig['noblock'] : 0;
		$server_serialize_type = isset($serviceConfig['serialize_type']) ? $serviceConfig['serialize_type'] : 'json';
		$key = md5($serviceName);
		
		if(!isset(self::$client_services[$key])) {
			self::$client_pack_setting[$key] = $client_pack_setting;
			$pack_length_key = isset($args['pack_length_key']) ? $args['pack_length_key'] : 'length';
			$client_serialize_type = isset($args['client_serialize_type']) ? $args['client_serialize_type'] : 'json';
			$swoole_keep = true;
			if(isset($args['swoole_keep']) && ($args['swoole_keep'] === false || $args['swoole_keep'] == 0)) {
				$swoole_keep = (boolean)$args['swoole_keep'];
			}
			$client_service = new RpcSynclient($client_pack_setting, $server_header_struct, $client_header_struct, $pack_length_key);
			$client_service->addServer($servers, $timeout, $noblock);
			$client_service->setClientServiceName($serviceName);
			$client_service->setClientSerializeType($client_serialize_type);
			$client_service->setServerSerializeType($server_serialize_type);
			$client_service->setSwooleKeep($swoole_keep);
			$client_service->setSwooleEnv($this->is_swoole_env);

			$swoole_client = $client_service->connect();
			if($this->is_swoole_env) {
				self::$client_services[$key] = \Swoole\Serialize::pack($client_service);
			}else {
				self::$client_services[$key] = serialize($client_service);
			}
			
		}

		return $client_service;
	}

	/**
	 * getService 获取某个服务实例|所有正在工作的服务
	 * @param    String   $serviceName
	 * @return   object|array
	 */
	public function getServices(string $serviceName = '') {
		if($serviceName) {
			$key = md5($serviceName);
			if(isset(self::$client_services[$key])) {
				// 深度复制client_service实例
				if($this->is_swoole_env) {
					$client_service = \Swoole\Serialize::unpack(self::$client_services[$key]);
				}else {
					$client_service = unserialize(self::$client_services[$key]);
				}		
				$us = strstr(microtime(), ' ', true);
        		$client_id = intval(strval($us * 1000 * 1000) . mt_rand(100, 999));
				if(!isset(self::$busy_client_services[$client_id])) {
					self::$busy_client_services[$client_id] = $client_service;
				}
				return $client_service;
			}
		}
		return self::$busy_client_services;
	}


	/**
	 * getSwooleClient 获取swoole_client实例
	 * @param    string   $serviceName
	 * @return   swoole_client
	 */
	public function getSwooleClients(string $serviceName = '') {
		if($serviceName) {
			if($this->getServices($serviceName)) {
				return $this->getServices($serviceName)->client;
			}
		}
		return false;
	}

	/**
     * multiRecv 规定时间内并行接受多个I/O的返回数据
     * @param    int   $timeout
     * @param    int   $size
     * @param    int   $flags
     * @return   array
     */
    public function multiRecv($timeout = 30, $size = 64 * 1024, $flags = 0) {
        $busy_client_services = $this->getServices();
        $client_services = $busy_client_services;
        $start_time = time();
        $this->response_pack_data = [];
        while(!empty($client_services)) {
        	$read = $write = $error = $client_ids = [];
	        foreach($client_services as $client_id=>$client_service) {
	        	$read[] = $client_service->getSwooleClient();
	        	$client_ids[] = $client_id;
	        	$client_service->setRecvWay(RpcClientConst::MULTI_RECV);
	        }
	        $ret = swoole_client_select($read, $write, $error, 0.50);
	        if($ret) {
	            foreach($read as $k=>$swoole_client) {
	                $data = $swoole_client->recv($size, $flags);
	                $client_id = $client_ids[$k];
	                $client_service = $client_services[$client_id];
	                if($data) {
	                    if($client_service->isPackLengthCheck()) {
	                    	$response = $client_service->depack($data);
			                list($header, $body_data) = $response;
			                $request_id = $client_service->getRequestId();
			                if(in_array($request_id, array_values($header))) {
			                	$client_service->setStatusCode(RpcClientConst::ERROR_CODE_SUCCESS);
			                	$this->response_pack_data[$request_id] = $response;
			                }else {
			                	$client_service->setStatusCode(RpcClientConst::ERROR_CODE_NO_MATCH);
			                	$this->response_pack_data[$request_id] = [];
			                }
	                    }else {
	                    	// eof分包时
	                    	$serviceName = $client_service->getClientServiceName();
	                        $unseralize_type = $client_service->getClientSerializeType();
	                        $this->response_pack_data[$serviceName] = $client_service->depackeof($data, $unseralize_type);
	                    }
	                }
	                unset($client_services[$client_id]);   
	            }   
	        }

	        $end_time = time();
	        if(($end_time - $start_time) > $timeout) {
				// 超时的client_service实例
		        foreach($client_services as $client_id=>$timeout_client_service) {
	            	$request_id = $timeout_client_service->getRequestId();
	            	$timeout_client_service->setStatusCode(RpcClientConst::ERROR_CODE_CALL_TIMEOUT);
	            	$this->response_pack_data[$request_id] = [];
	            	unset($client_services[$client_id]);
		       	}
		       	break;
			}
        }
        // client获取数据完成后，释放工作的client_services的实例
        $this->destroyBusyClient();
        return $this->response_pack_data;
    }

    /**
     * getAllResponseData 获取所有调用请求的swoole_client_select的I/O响应包数据
     * @return   array
     */
    public function getAllResponsePackData() {
    	return $this->response_pack_data;
    }

    /**
     * getResponsePackData 获取某个服务请求服务返回的数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackData(RpcSynclient $client_service) {
    	return $client_service->getResponsePackData();
    }

    /**
     * getResponseBody 获取服务响应的包体数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackBody(RpcSynclient $client_service) {
        return $client_service->getResponsePackBody();
    }

    /**
     * getResponseBody 获取服务响应的包头数据
     * @param   object  $client_service
     * @return  array
     */
    public function getResponsePackHeader(RpcSynclient $client_service) {
        return $client_service->getResponsePackHeader();
    }

    /**
     * destroyBusyClient client获取数据完成后，清空这些实例对象，实际上对象并没有真正销毁，因为在业务中还返回给一个变量引用着，只是清空self::$busy_client_services数组
     * eg $client = RpcClientManager::getInstance()->getServices('productService'); $client这个变量引用实例
     * @return  void 
     */
    public function destroyBusyClient() {
    	self::$busy_client_services = [];
    }

	/**
	 * getSetting 通过服务名获取客户端服务配置
	 * @param    string   $serviceName
	 * @return   array
	 */
	public function getClientPackSetting(string $serviceName) {
		$key = md5($serviceName);
		$client_service = self::$client_pack_setting[$key];
		if(is_object($client_service)) {
			return $client_service->getClientPackSetting();
		}
		return null;
	}

	/**
     * string 随机生成一个字符串
     * @param   int  $length
     * @param   bool  $number 只添加数字
     * @param   array  $ignore 忽略某些字符串
     * @return string
     */
    public function string($length = 6, $number = true, $ignore = []) {
        $strings = 'ABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        $numbers = '0123456789';           
        if($ignore && is_array($ignore)) {
            $strings = str_replace($ignore, '', $strings);
            $numbers = str_replace($ignore, '', $numbers);
        }
        $pattern = $strings . $numbers;
        $max = strlen($pattern) - 1;
        $key = '';
        for($i = 0; $i < $length; $i++) {   
            $key .= $pattern[mt_rand(0, $max)]; 
        }
        return $key;
    }

	/**
	 * buildHeader  重建header的数据，产生一个唯一的请求串号id
	 * @param    array   $header_data
	 * @param    string  $request_id_key
	 * @param    string  $length     默认12字节
	 * @return   array
	 */
	public function buildHeaderRequestId(array $header_data, $request_id_key = 'request_id', $length = 12) {
		$time = time();
		$key = $this->string();
        $request_id = (string)$time.$key;
		$request_id = substr(md5($request_id), 0, $length);
		$header = array_merge($header_data, [$request_id_key => $request_id]);
		return $header;
	}

}