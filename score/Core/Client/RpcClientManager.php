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

use Swoolefy\Core\Client\Synclient;

class RpcClientManager {

	use \Swoolefy\Core\SingleTrait;

	/**
	 * $setting 客户端解析设置
	 * @var array
	 */
	protected $setting = [];

	/**
	 * $client_services 客户端服务实例
	 * @var array
	 */
	protected static $client_services = [];

	/**
	 * $client_pack_setting client的包设置
	 * @var array
	 */
	protected static $client_pack_setting = [];

	/**
	 * $header_structs pack的头部结构体
	 * @var array
	 */
	protected static $header_structs = [];

	/**
	 * __construct 
	 * @param  array $setting
	 */
	public function __construct(array $setting=[]) {
		$this->setting = array_merge($this->setting, $setting);
	}

	/**
	 * registerService 注册服务
	 * @param    string    $serviceNmae
	 * @param    array     $serviceConfig
	 * @param    array     $setting
	 * @param    array     $header_struct
	 * @param    string    $pack_length_key
	 * @return   object
	 */
	public function registerService(string $serviceNmae, array $serviceConfig = [], array $setting = [], array $header_struct = [], string $pack_length_key = 'length', string $client_serialize_type = 'json') {
		$servers = $serviceConfig['servers'];
		$timeout = $serviceConfig['timeout'];
		$noblock = $serviceConfig['noblock'] ?: 0;
		$key = md5($serviceNmae);
		if(empty($setting)) {
			$setting = $this->setting;
		}
		if(!isset(self::$client_services[$key])) {
			self::$client_pack_setting[$key] = $setting;
			self::$header_structs[$key] = $header_struct;
			$client_service = new Synclient($setting, $header_struct, $pack_length_key);
			$client_service->addServer($servers, $timeout, $noblock);
			$client_service->setClientServiceName($serviceNmae);
			$client_service->setClientSerializeType($client_serialize_type);
			$swoole_client = $client_service->connect();
			self::$client_services[$key] = $client_service;
		}else {
			throw new \Exception("$serviceNmae service had exist", 1);
		}

		return $client_service;
	}

	/**
	 * getService 获取某个服务实例|所有服务
	 * @param    String   $serviceNmae
	 * @return   object|array
	 */
	public function getServices(string $serviceNmae = '') {
		if($serviceNmae) {
			$key = md5($serviceNmae);
			if(isset(self::$client_services[$key])) {
				return self::$client_services[$key];
			}
		}
		return self::$client_services;
	}


	/**
	 * getSwooleClient 获取swoole_client实例
	 * @param    string   $serviceNmae
	 * @return   swoole_client
	 */
	public function getSwooleClients(string $serviceNmae = '') {
		if($serviceNmae) {
			if($this->getServices($serviceNmae)) {
				return $this->getServices($serviceNmae)->client;
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
    public function multiRecv($timeout = 10, $size = 64 * 1024, $flags = 0) {
        $client_services = $this->getServices();
        $read = $write = $error = [];
        $client_services = array_values($client_services);
        foreach($client_services as $k=>$client_service) {
        	$read[$k] = $client_service->getSwooleClient();
        	// 恢复标志是否调用waitCall方法的控制位
        	$client_service->is_has_call_menthod = false;
        }
        $ret = swoole_client_select($read, $write, $error, $timeout);
        $this->response_pack_data = [];
        if($ret) {
            foreach($read as $k=>$swoole_client) {
                $data = $swoole_client->recv($size, $flags);
                $client_service = $client_services[$k];
                $serviceNmae = $client_service->getClientServiceName();
                if($data) {
                    if($client_service->isPackLengthCheck()) {
                    	$response = $client_service->depack($data);
		                list($header, $body_data) = $response;
		                // 不属于当前调用返回的值
		                if(!in_array($client_service->getRequestId(), array_values($header))) {
		                	// 返回[]
		                    $this->response_pack_data[$serviceNmae] = [];
		                }else {
		                	$this->response_pack_data[$serviceNmae] = $response;
		                }
                    }else {
                        $unseralize_type = $client_service->getClientSerializeType();
                        $this->response_pack_data[$serviceNmae] = $client_service->depackeof($data, $unseralize_type);
                    }
                }
            }
        }
        return $this->response_pack_data;
    }

    /**
     * getResponsePackData 获取服务返回的数据
     * @param   string  $serviceName
     * @return  array
     */
    public function getResponseData(string $serviceName) {
    	if(!empty($this->response_pack_data)) {
    		return $this->response_pack_data[$serviceName];
    	}
    	return false;
    }

	/**
	 * getSetting 通过服务名获取客户端服务配置
	 * @param    string   $serviceNmae
	 * @return   array
	 */
	public function getClientPackSetting(string $serviceNmae) {
		$key = md5($serviceNmae);
		if(isset(self::$client_pack_setting[$key])) {
			return self::$client_pack_setting[$key];
		}
		return false;
	}

	/**
     * string 随机生成一个字符串
     * @param   int  $length
     * @param   bool  $number 只添加数字
     * @param   array  $ignore 忽略某些字符串
     * @return string
     */
    public function string($length = 6, $number = true, $ignore = []) {
        //字符池
        $strings = 'ABCDEFGHIJKLOMNOPQRSTUVWXYZ';
        //数字池
        $numbers = '0123456789';           
        if($ignore && is_array($ignore)) {
            $strings = str_replace($ignore, '', $strings);
            $numbers = str_replace($ignore, '', $numbers);
        }
        $pattern = $strings . $numbers;
        $max = strlen($pattern) - 1;
        $key = '';
        for($i = 0; $i < $length; $i++) {   
            //生成php随机数
            $key .= $pattern[mt_rand(0, $max)]; 
        }
        return $key;
    }

	/**
	 * buildHeader  重建header的数据，产生一个唯一的请求串号id
	 * @param    array   $header_data
	 * @param    string  $request_id_key
	 * @param    string  $length     12字节
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