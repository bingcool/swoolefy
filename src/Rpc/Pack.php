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

namespace Swoolefy\Rpc;

class Pack {

	/**
	 * $server 
	 * @var null
	 */
	protected $server = null;

	/**
	 * $_buffer 对于一个连接没有收到完整数据包的数据进行暂时的缓存，等待此链接的数据包接收完整在处理
	 * @var array
	 */
	protected $_buffers = [];

	/**
	 * $_header 保存一个不完整包的包头数据
	 * @var array
	 */
	protected $_headers = [];

	/**
	 * $_pack_size 包的大小，实际应用应设置与package_max_length设置保持一致，默认2M
	 * @var integer
	 */
	protected static $packet_maxlen = 2 * 1024 * 1024;

	/**
	 * $header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
	 * @var array
	 */
	protected static $header_struct = ['length'=>'N'];

	/**
	 * $pack_length_type pack包数据的类型, 默认null
	 * @var null
	 */
	protected static $pack_length_type = null;

	/**
	 * $unpack_length_type unpack包数据的类型, 默认null
	 * @var null
	 */
	protected static $unpack_length_type = null;

	/**
	 * $pack_length_key 包的长度设定的key，与$header_struct里设置的长度的key一致
	 * @var string
	 */
	protected static $pack_length_key = 'length';

	/**
	 * $serialize_type 设置数据序列化的方式 
	 * @var string
	 */
	protected static $serialize_type = 'json';

	/**
	 * $header_length 包头固定长度，单位字节,等于package_body_offset设置的值
	 * @var integer
	 */
	protected static $header_length = 30;

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

    const ERR_HEADER            = 9001;   //错误的包头
    const ERR_TOOBIG            = 9002;   //请求包体长度超过允许的范围
    const ERR_SERVER_BUSY       = 9003;   //服务器繁忙，超过处理能力
    
	/**
	 * __construct 
	 * @param    \Swoole\Server $server
	 */
	public function __construct(\Swoole\Server $server) {
		$this->server = $server;
	}

	/**
	 * setHeaderStruct 设置服务端包头结构体
	 * @param  array $pack_header_struct 服务端包头结构体
	 */
	public function setHeaderStruct(array $pack_header_struct = []) {
		self::$header_struct = array_merge(self::$header_struct, $pack_header_struct);
		return true;
	}

	/**
	 * setPackLengthKey 
	 * @param string $pack_length_key 包头长度key
	 */
	public function setPackLengthKey(string $pack_length_key = 'length') {
		self::$pack_length_key = $pack_length_key;
		return true;
	}

	/**
	 * setSerializeType
	 * @param string $serialize_type
	 */
	public function setSerializeType(string $serialize_type = 'json') {
		self::$serialize_type = $serialize_type;
		return true;
	}

	/**
	 * setHeaderLength
	 * @param int|null $header_length
	 */
	public function setHeaderLength(int $header_length = null) {
		self::$header_length = $header_length;
	}

	/**
	 * setPacketMaxlen 包的最大长度
	 * @param int|null $packet_maxlen
	 */
	public function setPacketMaxlen(int $packet_maxlen = null) {
		self::$packet_maxlen = $packet_maxlen;
	}

	/**
	 * depack 
	 * @param    \Swoole\Server $server
	 * @param    int            $fd
	 * @param    int            $reactor_id
	 * @param    string         $data
	 * @return   mixed
	 */
	public function depack(\Swoole\Server $server, $fd, $reactor_id, $data) {
		//接收的包数据不完整的
		if(isset($this->_buffers[$fd])) {
			/**
			 * 每次再接收的数据是属于上一个不完整的包的,已经没有包头了,直接包体数据
			 */
			$this->_buffers[$fd] .= $data;
			$buffer_fd_data_length = strlen($this->_buffers[$fd]);

			/**
			 * 包长度超出最大设置限度
			 */
			if($buffer_fd_data_length > self::$packet_maxlen) {
				$this->sendErrorMessage($fd, self::ERR_TOOBIG);
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				return false;
			}

			if($buffer_fd_data_length < $this->_headers[$fd][self::$pack_length_key]) {
				return false;
			}else {
				//数据包已接收完整
				$pack_body = $this->_buffers[$fd];
				// 数据解包
				$data = $this->decode($pack_body, self::$serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				// 返回包头和包体数据
				return $request;
			}

		}else {
			// 设置unpack包类型
			$this->setUnpackLengthType();
			// 解析包头
			$header = unpack(self::$unpack_length_type, mb_strcut($data, 0, self::$header_length, 'UTF-8'));

			$this->filterHeader($header);
			// 包头包含的包体长度值
			$length = $header[self::$pack_length_key];

			$this->_headers[$fd] = $header;

			$pack_body = mb_strcut($data, self::$header_length, null, 'UTF-8');
			// 接收数据包完整
			if(strlen($pack_body) == $length) {
				// 数据解包
				$data = $this->decode($pack_body, self::$serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffers[$fd], $this->_headers[$fd]);
				// 返回包头和包体数据
				return $request;
			}else {
				// 包数据不完整，先缓存
				$this->_buffers[$fd] .= $pack_body;
				return false;
			}
		}
		
	}

	/**
	 * setUnPackLengthType  设置unpack头的类型
	 * @return   string 
	 */
	public function setUnpackLengthType(array $struct=[]) {
		if(self::$unpack_length_type) {
			return self::$unpack_length_type;
		}

		$pack_length_type = '';
		if(self::$header_struct) {
			foreach(self::$header_struct as $key=>$value) {
				$pack_length_type .= ($value.$key).'/';
			}
		}
		$pack_length_type = trim($pack_length_type, '/');
		// 赋值
		self::$unpack_length_type = $pack_length_type;
		return $pack_length_type;
	}

	/**
	 * sendErrorMessage 
	 * @param    int  $fd
	 * @param    mixed  $errno
	 * @return   boolean
	 */
	public function sendErrorMessage($fd, $errno) {
        return $this->server->send($fd, self::encode(['errcode' => $errno,'msg'=>'packet length more than packet_maxlen'], self::$serialize_type));
    }

    /**
     * filterHeader  去掉头部空格|null
     * @param    &$header 
     * @return   array
     */
	public function filterHeader(&$header) {
		foreach($header as $key=>&$value) {
			$header[$key] = trim($value);
		}
		return $header;
	}

	/**
	 * encode 数据封包
	 * usages:
	 	$header = ['length'=>'','name'=>'bingcool'];头部字段信息,'length'字段可以为空
	 * @param    mixed  $data
	 * @param    array  $header
	 * @param    string  $serialize_type
	 * @param    array  $heder_struct
	 * @return   string
	 */
	public static function enpack($data, array $header, array $header_struct = [], $pack_length_key ='length', $serialize_type = self::DECODE_JSON) {
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
	 * encode 数据序列化
	 * @param   mixed   $data
	 * @param   int     $serialize_type
	 * @return  string
	 */
	public static function encode($data, $serialize_type = self::DECODE_JSON) {
		if(is_string($serialize_type)) {
			$serialize_type = self::SERIALIZE_TYPE[$serialize_type];
		}
        switch($serialize_type) {
        		// json
            case 1:
                return json_encode($data, JSON_UNESCAPED_UNICODE);
            default:
            	// serialize
            	return serialize($data);
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
            default:
            	// serialize
            	return unserialize($data);
        }
    }

    /**
     * delete 删除缓存的不完整的僵尸式数据包，可以在onclose回调中执行,防止内存偷偷溢增
     * @param int  $fd
     * @return boolean
     */
    public function delete($fd) {
    	unset($this->_buffers[$fd], $this->_headers[$fd]);
    	return true;
    }

    /**
     * destroy 当workerStop时,删除缓冲的不完整的僵尸式数据包，并强制断开这些链接
     * @param mixed $server
     * @param int $worker_id
     * @return boolean
     */
    public function destroy($server = null, $worker_id = null) {
    	if(!empty($this->_buffers)) {
    		foreach($this->_buffers as $fd=>$data) {
    			$this->server->close($fd, true);
    			unset($this->_buffers[$fd], $this->_headers[$fd]);
    		}
    		return true;
    	}
    }
}
