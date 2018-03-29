<?php
namespace Swoolefy\Core\Client;

class Synclient {
	/**
	 * $client client对象
	 * @var [type]
	 */
	public $client;

	/**
	 * $header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
	 * @var array
	 */
	public $header_struct = ['length'=>'N','name'=>'a30'];

	/**
	 * $pack_setting 分包数据协议设置
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
	public $serialize_type = 'json';

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

    // 是否是使用pack_length来分包
 	protected $is_pack_length_type = true;

    /**
     * __construct 初始化
     * @param array $setting
     */
    public function __construct(array $setting=[]) {
    	$this->pack_setting = array_merge($this->pack_setting, $setting);

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
   	 * @param integer $nonblock
   	 */
    public function addServer($servers, $timeout = 0.5, $nonblock = 0) {
    	if(is_array($servers)) {
    		$this->remote_servers = $servers;
    	}else {
    		if(strpos($value, ':')) {
    			list($host, $port) = explode(':', $value);
    			$this->remote_servers = [$host, $port];
    		}
    	}
    	$this->timeout = $timeout;
    }

    /**
     * connect 连接
     * @param  syting  $host   
     * @param  string  $port   
     * @param  float   $tomeout
     * @param  integer $nonlock
     * @return mixed          
     */
    public function connect($host = null, $port = null , $tomeout = 0.5, $nonlock = 0) {
    	if(!empty($host) && !empty($port)) {
    		$this->remote_servers = [$host, $port];
    		$this->timeout = $timeout;
    	}

    	// 如果存在swoole扩展，优先使用swoole扩展
    	if($this->haveSwoole) {
    		// 创建同步客户端
    		$client = new \swoole_client(SWOOLE_TCP | SWOOLE_KEEP, SWOOLE_SOCK_SYNC);
    		$client->set($this->pack_setting);

    		foreach($this->remote_servers as $val) {
    			$host = $val['host'];
    			$port = $val['port'];
    			$client->connect($host, $port);
    			/**
             	* 尝试重连一次
             	*/
	            for ($i = 0; $i < 2; $i++)
	            {
	                $ret = $client->connect($host, $port, $this->timeout, 0);
	                if ($ret === false and ($client->errCode == 114 or $client->errCode == 115)) {
	                    //强制关闭，重连
	                    $client->close(true);
	                    continue;
	                }else {
	                    break;
	                }
	            }
    		}
    		
    	}else if($this->haveSockets) {

    	}else {
    		return false;
    	}

    	$this->client = $client;

    	return $client;
    }

    /**
     * send 数据发送
     * @param   mixed $data
     * @return  boolean
     */
	public function send($data) {
		if(!$this->client->isConnected()) {
			$this->client->close(true);
			list($host, $port) = $this->remote_servers;
			$this->client->connect($host, $port);
		}

		$res = $this->client->send($data);
		// 发送成功
		if($res) {
			return true;
		}else {
			throw new \Exception("swoole_client error : ".$this->client->errCode);
			return false;
		}

	}

	/**
	 * recv 接收数据
	 * @param    int  $size  
	 * @param    int  $flags 
	 * @return   array
	 */
	public function recv($size = 64 * 1024, $flags = 0) {
		$data = $this->client->recv($size, $flags);
		if($data) {
			if($this->is_pack_length_type) {
				return $this->depack($data);
			}else {
				$unseralize_type = $this->serialize_type;
				return $this->depackeof($data, $unseralize_type);
			}
		}else {
			$this->client->close(true);
			throw new \Exception("swoole_client recv error : ".$this->client->errCode);
		}
	}

	/**
	 * close 关闭
	 * @return 
	 */
	public function close($isforce = false) {
		return $this->client->close($isforce);
	}

	/**
	 * enpack 
	 * @param  array $data
	 * @param  array  $header
	 * @param  mixed  $serialize_type
	 * @param  array  $heder_struct
	 * @param  string $pack_length_key
	 * @return mixed                 
	 */
	public static function enpack($data, $header, array $header_struct = [], $pack_length_key ='length', $serialize_type = self::DECODE_JSON) {

		$body = self::encode($data, $serialize_type);
        $bin_header_data = '';

        // 如果没有设置，客户端的包头结构体与服务端一致
        if(empty($header_struct)) {
        	$header_struct = self::$header_struct;
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
		$body_data = json_decode(mb_strcut($data, 34, null, 'UTF-8'), true);

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
     * usages:
     *	Syncilent::$pack_eof = "\r\n\r\n";
	 *	$client = new Syncilent();
	 *	$sendData = $client->enpackeof($data, Syncilent::DECODE_JSON);				
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
     * usages:
     *	Syncilent::$pack_eof = "\r\n\r\n";
	 *	$client = new Syncilent();
	 *	$sendData = $client->depackeof($data, Syncilent::DECODE_JSON);
     * @param   string  $data
     * @param   int     $unseralize_type
     * @return  mixed 
     */
    public function depackeof($data, $unserialize_type = self::DECODE_JSON) {
    	return self::decode($data, $unserialize_type);
    }

    /**
     * __set 魔术方法设置pack_eof
     * useage:
	 *	$client = new Syncilent();
	 *	$cilent->pack_eof = "\r\n\r\n";
     * @param [type] $name  [description]
     * @param [type] $value [description]
     */
    public function __set($name, $value) {
    	switch($name) {
    		case "pack_eof" :
    		if(is_string($value)) {
    			self::$pack_eof = $value;
    		}
    		break;
    		default;return;
    	}
    }
}