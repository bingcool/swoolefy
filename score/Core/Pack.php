<?php 
namespace Swoolefy\Core;

class Pack {

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
	 * $_header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
	 * @var string
	 */
	public static $_header_struct = ['length'=>'N','name'=>'a30'];

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
	 * $pack_length_key 包的长度设定的key，与$_header_struct里设置的长度的key一致
	 * @var string
	 */
	public static $pack_length_key = 'length';

	/**
	 * $serialize_type 设置数据序列化的方式 
	 * @var [type]
	 */
	public $serialize_type = 'json';

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
	 * $_header_size 包头固定大小
	 * @var integer
	 */
	public static $_header_size = 34;

	/**
	 * decodePack 接收数据解包处理
	 * @param    \Swoole\Server $server
	 * @param    int            $fd
	 * @param    int         $reactor_id
	 * @param    string         $data
	 * @return   mixed
	 */
	public function depack(\Swoole\Server $server, $fd, $reactor_id, $data) {
		//接收的包数据不完整的
		if(isset($this->_buffers[$fd])) {
			// 每次再接收的数据是属于上一个不完整的包的，已经没有包头了，直接包体数据
			$this->_buffers[$fd] .= $data;

			if(strlen($this->_buffers[$fd]) < $this->_headers[$fd][self::$pack_length_key]) {
				return false;
			}else {
				// 数据包已接收完整
				$pack_body = $this->_buffers[$fd];
				// 数据解包
				$data = $this->decode($pack_body, $this->serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffer[$fd], $this->_headers[$fd]);
				// 返回包头和包体数据
				return $request;
			}


		}else {
			// 设置unpack包类型
			$this->setUnpackLengthType();
			// 解析包头
			$header = unpack(self::$unpack_length_type, mb_strcut($data, 0, self::$_header_size, 'UTF-8'));

			$this->filterHeader($header);
			// 包头包含的包体长度值
			$length = $header[self::$pack_length_key];

			$header['fd'] = $fd;
			$this->_headers[$fd] = $header;

			$pack_body = mb_strcut($data, self::$_header_size, null, 'UTF-8');
			// 接收数据包完整
			if(strlen($pack_body) == $length) {
				// 数据解包
				$data = $this->decode($pack_body, $this->serialize_type);

				$request = [$this->_headers[$fd], $data];
				unset($this->_buffer[$fd], $this->_headers[$fd]);
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
	 * setPackLengthType  设置pack头的类型
	 * @return   string 
	 */
	public function setPackLengthType(array $struct=[]) {
		if(self::$pack_length_type) {
			return self::$pack_length_type;
		}

		$pack_length_type = '';
		if(self::$_header_struct) {
			foreach(self::$_header_struct as $key=>$value) {
				$pack_length_type .= $value;
			}
		}
		// 赋值
		self::$pack_length_type = $pack_length_type;
		return $pack_length_type;
	}

	/**
	 * setPackLengthType  设置unpack头的类型
	 * @return   string 
	 */
	public function setUnpackLengthType(array $struct=[]) {
		if(self::$unpack_length_type) {
			return self::$unpack_length_type;
		}

		$pack_length_type = '';
		if(self::$_header_struct) {
			foreach(self::$_header_struct as $key=>$value) {
				$pack_length_type .= ($value.$key).'/';
			}
		}
		// 赋值
		self::$unpack_length_type = trim($pack_length_type, '/');
		return $pack_length_type;
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
	 * encode 数据序列化
	 * @param    string  $data
	 * @param    array  $header
	 * @param    string  $seralize_type
	 * @return   string
	 */
	public static function enpack($data, $header, $seralize_type = self::DECODE_JSON) {
		if(is_string($seralize_type)) {
			$seralize_type = self::SERIALIZE_TYPE[$seralize_type];
		}
        switch($seralize_type) {
        		// json
            case 1:
                $body = json_encode($data);
                // serialize
            case 2:
            	$body = serialize($data);
            case 3;
            default:
            	// swoole
            	$body = swoole_pack($data);  
        }

        $bin_header_data = '';

        foreach(self::$_header_struct as $key=>$value) {
        	// 计算包体长度
        	if($key == self::$pack_length_key) {
        		$bin_header_data .= pack($value, strlen($body));
        	}else {
        		// 其他的包头
        		$bin_header_data .= pack($value, $header[$key]);
        	}
        	 
        }

        return $bin_header_data . $body;
	}

	/**
	 * decode 数据反序列化
	 * @param    string   $data
	 * @param    mixed    $unseralize_type
	 * @return   mixed
	 */
	public static function decode($data, $unseralize_type = self::DECODE_JSON) {
		if(is_string($unseralize_type)) {
			$unseralize_type = self::SERIALIZE_TYPE[$unseralize_type];
		}
        switch($unseralize_type) {
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

}
