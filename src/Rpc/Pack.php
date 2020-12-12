<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Rpc;

use Swoolefy\Core\Application;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;

class Pack extends BaseParse {

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
	 * setPacketMaxlength 包的最大长度
	 * @param int|null $packet_maxlength
	 */
	public function setPacketMaxlength(int $packet_maxlength = null) {
		self::$packet_maxlen = $packet_maxlength;
	}

    /**
     * decodePack swoole底层已经根据配置的解包协议分割tcp数据,data是一个完整的数据包
     * @param $fd
     * @param $data
     * @return array|bool
     */
	public function decodePack(
        $fd,
        $data
    ) {
        /**
         * unpack包类型
         */
        $this->parseUnpackType();

        /**
         * 解析包头
         */
        $header = unpack(self::$unpack_length_type, mb_strcut($data, 0, self::$header_length, 'UTF-8'));

        $this->filterHeader($header);

        /**
         * 包头包含的包体长度值
         */
        $length = $header[self::$pack_length_key];

        $this->_headers[$fd] = $header;

        $pack_body = mb_strcut($data, self::$header_length, $length, 'UTF-8');

        $pack_data = $this->decode($pack_body, self::$serialize_type);

        if($pack_data === null || $pack_data === false || $pack_data === '') {
            $error_msg = 'Parse Packet Error, May Be Packet Encoding Error';
            $this->sendErrorMessage($fd, parent::ERR_PARSE_BODY, $error_msg, $header);
            throw new \Exception(sprintf(
                "errorMsg:%s,header=%s,body=%s",
                $error_msg,
                json_encode($header, JSON_UNESCAPED_UNICODE),
                $pack_body
            ));
        }

        $payload = [$this->_headers[$fd], $pack_data];
        unset($this->_buffers[$fd], $this->_headers[$fd]);
        return $payload;
	}

	/**
	 * parseUnpackType  设置unpack头的类型
	 * @return   string 
	 */
	public function parseUnpackType(array $header_struct = []) {
        $pack_length_type = '';
		if(self::$unpack_length_type) {
			return self::$unpack_length_type;
		}
		if($header_struct) {
            self::$header_struct = $header_struct;
        }
		if(self::$header_struct) {
			foreach(self::$header_struct as $key=>$value) {
				$pack_length_type .= ($value.$key).'/';
			}
		}
		$pack_length_type = trim($pack_length_type, '/');
		self::$unpack_length_type = $pack_length_type;
		return $pack_length_type;
	}

	/**
	 * sendErrorMessage 
	 * @param    int  $fd
	 * @param    mixed  $errno
	 * @return   boolean
	 */
	public function sendErrorMessage($fd, $errno, $errorMsg, array $header) {
	    $responseData = Application::buildResponseData($errno, $errorMsg);
        if(BaseServer::isPackLength()) {
            $payload = [$responseData, $header];
            $data = \Swoolefy\Rpc\RpcServer::pack($payload);
            return Swfy::getServer()->send($fd, $data);
        }else if(BaseServer::isPackEof()) {
            $text = \Swoolefy\Rpc\RpcServer::pack($responseData);
            return Swfy::getServer()->send($fd, $text);
        }
    }

    /**
     * filterHeader  filter头部空格
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
	 * encodePack 数据封包
	 * usages:
	 	$header = ['length'=>'','name'=>'bing'];头部字段信息,'length'字段可以为空
	 * @param    mixed  $data
	 * @param    array  $header
	 * @param    string  $serialize_type
	 * @param    array  $heder_struct
	 * @return   string
	 */
	public static function encodePack(
	    $data,
        array $header,
        array $header_struct = [],
        $pack_length_key ='length',
        $serialize_type = self::DECODE_JSON
    ) {
        $bin_header_data = '';
	    $body = self::encode($data, $serialize_type);
        /**
         * 如果没有设置，客户端的包头结构体与服务端一致
         */
        if(empty($header_struct)) {
        	$header_struct = self::$header_struct;
        }
        if(!isset($header_struct[$pack_length_key])) {
            $header_struct[$pack_length_key] = '';
        }
        foreach($header_struct as $key=>$value) {
        	if(isset($header[$key])) {
        		// length packet header
	        	if($key == $pack_length_key) {
	        		$bin_header_data .= pack($value, strlen($body));
	        	}else {
	        		// other packet header
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
	 * @param    mixed    $unserialize_type
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
                break;
            default:
            	// serialize
            	return unserialize($data);
            	break;
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
