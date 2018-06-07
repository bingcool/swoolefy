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

class Synclient {
	/**
	 * $client client对象
	 * @var [type]
	 */
	public $client;

    /**
     * $clientService 客户端的对应服务名
     * @var [type]
     */
    public $clientServiceName;

	/**
	 * $header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
	 * @var array
	 */
	public $header_struct = ['length'=>'N'];

	/**
	 * $pack_setting client的分包数据协议设置
	 * @var array
	 */
	public $pack_setting = [];

	/**
	 * $pack_length_key 包的长度设定的key，与$header_struct里设置的长度的key一致
	 * @var string
	 */
	public $pack_length_key = 'length';

	/**
	 * $serialize_type 设置数据序列化的方式 
	 * @var string
	 */
	public $client_serialize_type = 'json';

    /**
     * 每次请求调用的串号id
     */
    public $request_id = null;

    /**
     * $is_has_call_menthod 是否调用了waitCall方法,，如果调用waitCall，则不能调用第二次，必须使用waitRecv()或者multiRecv()获取数据复位
     * @var boolean
     */
    public $is_has_call_menthod = false;

	/**
	 * $pack_eof eof分包时设置
	 * @var string
	 */
	public static $pack_eof = "\r\n\r\n";

	/**
	 * 定义序列化的方式
	 */
	const SERIALIZE_TYPE = [
		'json' => 1,
		'serialize' => 2,
		'swoole' => 3
	];

	const DECODE_JSON = 1;
    const DECODE_PHP = 2;
    const DECODE_SWOOLE = 3;

    /**
     * $remote_servers 请求的远程服务ip和端口
     * @var array
     */
    public $remote_servers = [];

    /**
     * $keeplive 是否保持长连接，默认true
     * @var boolean
     */
    public $keeplive = true;

    /**
     * $timeout 连接超时时间，单位s
     * @var float
     */
    public $timeout = 0.5;

    public $haveSwoole = false;
    public $haveSockets = false;

    /**
     * $is_pack_length_type client是否使用length的check来检查分包
     * @var boolean
     */
 	public $is_pack_length_type = true;

    /**
     * __construct 初始化
     * @param array $setting
     */
    public function __construct(array $setting=[], array $header_struct = [], string $pack_length_key = 'length') {
    	$this->pack_setting = array_merge($this->pack_setting, $setting);
        $this->header_struct = array_merge($this->header_struct, $header_struct);
        $this->pack_length_key = $pack_length_key;

    	$this->haveSwoole = extension_loaded('swoole');
        $this->haveSockets = extension_loaded('sockets');

        if(isset($this->pack_setting['open_length_check']) && isset($this->pack_setting['package_length_type'])) {
        	$this->is_pack_length_type = true;
        }else {
        	// 使用eof方式分包
        	$this->is_pack_length_type = false;
            self::$pack_eof = $this->pack_setting['package_eof'];
        }
    }

   	/**
   	 * addServer 添加服务器
   	 * @param mixed  $servers 
   	 * @param float   $timeout 
   	 * @param integer $noblock
   	 */
    public function addServer($servers, $timeout = 0.5, $noblock = 0) {
    	if(!is_array($servers)) {
    		if(strpos($servers, ':')) {
    			list($host, $port) = explode(':', $servers);
    			$servers = [$host, $port];
    		}
    	}
        $this->remote_servers[] = $servers;
    	$this->timeout = $timeout;
    }

    /**
     * setClientServiceName 设置当前的客户端实例的对应服务名
     * @param   string  $clientServiceName
     */
    public function setClientServiceName(string $clientServiceName) {
        return $this->clientServiceName = $clientServiceName;
    }

    /**
     * getClientServiceName 
     * @return  string
     */
    public function getClientServiceName() {
        return $this->clientServiceName;
    }

    /**
     * setClientSerializeType 设置client端数据的序列化类型
     * @param    string   $client_serialize_type
     */
    public function setClientSerializeType(string $client_serialize_type) {
        if($client_serialize_type) {
            $this->client_serialize_type = $client_serialize_type;
        }
    }

    /**
     * getClientSerializeType  获取客户端实例的序列化类型
     * @return  string
     */
    public function getClientSerializeType() {
        return $this->client_serialize_type;
    }

    /**
     * isPackLengthCheck  client是否使用length的检查
     * @return   boolean
     */
    public function isPackLengthCheck() {
        return $this->is_pack_length_type;
    }

    /**
     * connect 连接
     * @param  syting  $host   
     * @param  string  $port   
     * @param  float   $tomeout
     * @param  integer $noblock
     * @return mixed          
     */
    public function connect($host = null, $port = null , $timeout = 0.5, $noblock = 0) {
    	if(!empty($host) && !empty($port)) {
    		$this->remote_servers[] = [$host, $port];
    		$this->timeout = $timeout;
    	}
    	// 如果存在swoole扩展，优先使用swoole扩展
    	if($this->haveSwoole) {
    		// 创建长连接同步客户端
    		$client = new \swoole_client(SWOOLE_TCP | SWOOLE_KEEP, SWOOLE_SOCK_SYNC);
    		$client->set($this->pack_setting);
            $this->client = $client;
            // 重连一次
    		$this->reConnect();
    	}else if($this->haveSockets) {
            // TODO
    	}else {
    		return false;
    	}
    	return $client;
    }

    /**
     * getSwooleClient 获取当前的swoole_client实例
     * @return   swoole_client
     */
    public function getSwooleClient() {
        if(is_object($this->client)) {
            return $this->client;
        }
        return false;
    }

    /**
     * getRequestId  获取当前的请求的串号id
     * @return   string 
     */
    public function getRequestId() {
        return $this->request_id;
    }

    /**
     * send 数据发送
     * @param   mixed $data
     * @return  boolean
     */
	public function waitCall($data, array $header = [], $seralize_type = self::DECODE_JSON) {
        // 这里检测是应用层检测，不一定准确
		if(!$this->client->isConnected()) {
            // 重连一次
            $this->client->close(true);
			$this->reConnect();
		}
        if($this->is_has_call_menthod) {
            throw new \Exception("you had call waitCall()  method 1 times, you can not call 2 times before you call waitRecv() method or multiRecv() method !", 1);  
        }
        // 封装包
        $pack_data = self::enpack($data, $header, $this->header_struct, $this->pack_length_key, $seralize_type);
		$res = $this->client->send($pack_data);
		// 发送成功
		if($res) {
            if(isset($header['request_id'])) {
                $this->request_id = $header['request_id'];
            }else {
                $header_values = array_values($header);
                $this->request_id = end($header_values);
            }
            $this->is_has_call_menthod = true;
			return true;
		}else {
            // 重连一次
            $this->client->close(true);
            $this->reConnect();
            // 重发一次
            $res = $this->client->send($pack_data);
            if($res) {
                if(isset($header['request_id'])) {
                    $this->request_id = $header['request_id'];
                }else {
                    $header_values = array_values($header);
                    $this->request_id = end($header_values);
                }
                $this->is_has_call_menthod = true;
                return true;
            }
            
			return false;
		}
	}

	/**
	 * recv 阻塞等待接收数据
	 * @param    int  $size
	 * @param    int  $flags 
	 * @return   array
	 */
	public function waitRecv($timeout = 5, $size = 64 * 1024, $flags = 0) {
        // 恢复标志是否调用waitCall方法的控制位
        $this->is_has_call_menthod = false;
        if($this->client instanceof \swoole_client) {
            $read = array($this->client);
            $write = [];
            $error = [];
            $ret = swoole_client_select($read, $write, $error, $timeout);
            if($ret) {
                $data = $this->client->recv($size, $flags);
            }
        }

        if($data) {
            if($this->is_pack_length_type) {
                $response = $this->depack($data);
                list($header, $body_data) = $response;
                // 不属于当前调用返回的值
                if(!in_array($this->getRequestId(), array_values($header))) {
                    return [];
                }
                return $response;
            }else {
                $unseralize_type = $this->client_serialize_type;
                return $this->depackeof($data, $unseralize_type);
            }
        }
        return [];
	}

    /**
     * reConnect  最多尝试重连次数，默认尝试重连1次
     * @param   int  $times
     * @return  void
     */
    public function reConnect($times = 1) {
        foreach($this->remote_servers as $k=>$servers) {
            list($host, $port) = $servers;
            // 尝试重连一次
            for($i = 0; $i <= $times; $i++) {
                $ret = $this->client->connect($host, $port, $this->timeout, 0);
                if($ret === false && ($this->client->errCode == 114 || $this->client->errCode == 115)) {
                    //强制关闭，重连
                    $this->client->close(true);
                    continue;
                }else {
                    break;
                }
            }
        }
    }

	/**
	 * enpack 
	 * @param  array  $data
	 * @param  array  $header
	 * @param  mixed  $serialize_type
	 * @param  array  $heder_struct
	 * @param  string $pack_length_key
	 * @return mixed                 
	 */
	public static function enpack($data, $header, array $header_struct = [], $pack_length_key ='length', $serialize_type = self::DECODE_JSON) {
		$body = self::encode($data, $serialize_type);
        $bin_header_data = '';

        if(empty($header_struct)) {
        	throw new \Exception('you must set the $header_struct');
        }

        foreach($header_struct as $key=>$value) {
        	if(isset($header[$key])) {
        		// 计算包体长度
	        	if($key == $pack_length_key) {
	        		$bin_header_data .= pack($value, strlen($body));
	        	}else {
	        		// 其他的包头
	        		$bin_header_data .= pack($value, $header[$key]);
	        	}
        	} 
        }

        return $bin_header_data . $body;
	}

	/**
	 * depack 
	 * @param   mixed $data
	 * @return  array
	 */
	public function depack($data) {
		$unpack_length_type = $this->setUnpackLengthType();
		$package_body_offset = $this->pack_setting['package_body_offset'];
		$header = unpack($unpack_length_type, mb_strcut($data, 0, $package_body_offset, 'UTF-8'));
		$body_data = json_decode(mb_strcut($data, $package_body_offset, null, 'UTF-8'), true);
		return [$header, $body_data];
	}

	/**
	 * setPackLengthType  设置unpack头的类型
	 * @return   string 
	 */
	public function setUnpackLengthType() {
		$pack_length_type = '';

		if($this->header_struct) {
			foreach($this->header_struct as $key=>$value) {
				$pack_length_type .= ($value.$key).'/';
			}
		}
        
		$pack_length_type = trim($pack_length_type, '/');
		return $pack_length_type;
	}

	/**
	 * encode 数据序列化
	 * @param   mixed   $data
	 * @param   int     $seralize_type
	 * @return  string
	 */
	public static function encode($data, $serialize_type = self::DECODE_JSON) {
		if(is_string($serialize_type)) {
            $serialize_type = strtolower($serialize_type);
			$serialize_type = self::SERIALIZE_TYPE[$serialize_type];
		}
        switch($serialize_type) {
        		// json
            case 1:
                return json_encode($data);
                // serialize
            case 2:
            	return serialize($data);
            case 3;
            default:
            	// swoole
            	return swoole_pack($data);  
        }
	}

	/**
	 * decode 数据反序列化
	 * @param    string   $data
	 * @param    mixed    $unseralize_type
	 * @return   mixed
	 */
	public static function decode($data, $unserialize_type = self::DECODE_JSON) {
		if(is_string($unserialize_type)) {
            $serialize_type = strtolower($serialize_type);
			$unserialize_type = self::SERIALIZE_TYPE[$unserialize_type];
		}
        switch($unserialize_type) {
        		// json
            case 1:
                return json_decode($data, true);
                // serialize
            case 2:
            	return unserialize($data);
            case 3;
            default:
            	// swoole
            	return swoole_unpack($data);   
        }
    }

    /**
     * enpackeof eof协议封包,包体中不能含有eof的结尾符号，否则出错		
     * @param  mixed $data
     * @param  int   $seralize_type
     * @param  string $eof
     * @return string
     */
    public function enpackeof($data, $serialize_type = self::DECODE_JSON, $eof ='') {
    	if(empty($eof)) {
    		$eof = self::$pack_eof;
    	}
    	$data = self::encode($data, $serialize_type).$eof;
    	
    	return $data;
    }

    /**
     * depackeof  eof协议解包,每次收到一个完整的包
     * @param   string  $data
     * @param   int     $unseralize_type
     * @return  mixed 
     */
    public function depackeof($data, $unserialize_type = self::DECODE_JSON) {
    	return self::decode($data, $unserialize_type);
    }

    /**
     * close 关闭
     * @return 
     */
    public function close($isforce = false) {
        return $this->client->close($isforce);
    }

}