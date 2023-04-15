<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Rpc;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Application;

class Pack extends BaseParse
{

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
     * pack 包的大小，实际应用应设置与package_max_length设置保持一致，默认2M
     * @var int
     */
    protected static $packetMaxlength = 2 * 1024 * 1024;

    /**
     * $header_struct 析包结构,包含包头结构，key代表的是包头的字段，value代表的pack的类型
     * @var array
     */
    protected static $headerStruct = ['length' => 'N'];

    /**
     * $unpack_length_type unpack包数据的类型, 默认null
     * @var null $unpackLengthType
     */
    protected static $unpackLengthType = null;

    /**
     * $pack_length_key 包的长度设定的key，与$header_struct里设置的长度的key一致
     * @var string
     */
    protected static $packLngthKey = 'length';

    /**
     * $serialize_type 设置数据序列化的方式
     * @var string
     */
    protected static $serializeType = 'json';

    /**
     * $header_length 包头固定长度，单位字节,等于package_body_offset设置的值
     * @var int
     */
    protected static $headerLength = 30;

    /**
     * setHeaderStruct 设置服务端包头结构体
     * @param array $packHeaderStruct 服务端包头结构体
     */
    public function setHeaderStruct(array $packHeaderStruct = [])
    {
        self::$headerStruct = array_merge(self::$headerStruct, $packHeaderStruct);
        return true;
    }

    /**
     * setPackLengthKey
     * @param string $packLengthKey 包头长度key
     */
    public function setPackLengthKey(string $packLengthKey = 'length')
    {
        self::$packLngthKey = $packLengthKey;
        return true;
    }

    /**
     * setSerializeType
     * @param string $serializeType
     */
    public function setSerializeType(string $serializeType = 'json')
    {
        self::$serializeType = $serializeType;
        return true;
    }

    /**
     * setHeaderLength
     * @param int $headerLength
     */
    public function setHeaderLength(int $headerLength)
    {
        self::$headerLength = $headerLength;
    }

    /**
     * setPacketMaxlength 包的最大长度
     * @param int|null $packetMaxLength
     */
    public function setPacketMaxlength(int $packetMaxLength)
    {
        self::$packetMaxlength = $packetMaxLength;
    }

    /**
     * decodePack swoole底层已经根据配置的解包协议分割tcp数据,data是一个完整的数据包
     * @param int $fd
     * @param mixed $data
     * @return array|bool
     */
    public function decodePack(int $fd, mixed $data)
    {
        /**
         * unpack包类型
         */
        $this->parseUnpackType();

        /**
         * 解析包头
         */
        $header = unpack(self::$unpackLengthType, mb_strcut($data, 0, self::$headerLength, 'UTF-8'));

        $this->filterHeader($header);

        /**
         * 包头包含的包体长度值
         */
        $length = $header[self::$packLngthKey];

        $this->_headers[$fd] = $header;

        $pack_body = mb_strcut($data, self::$headerLength, $length, 'UTF-8');

        $pack_data = $this->decode($pack_body, self::$serializeType);

        if ($pack_data === null || $pack_data === false || $pack_data === '') {
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
     * @return string
     */
    public function parseUnpackType(array $headerStruct = [])
    {
        $packLengthType = '';

        if (self::$unpackLengthType) {
            return self::$unpackLengthType;
        }

        if ($headerStruct) {
            self::$headerStruct = $headerStruct;
        }

        if (self::$headerStruct) {
            foreach (self::$headerStruct as $key => $value) {
                $packLengthType .= ($value . $key) . '/';
            }
        }

        $packLengthType = trim($packLengthType, '/');
        self::$unpackLengthType = $packLengthType;
        return $packLengthType;
    }

    /**
     * @param int $fd
     * @param int $errno
     * @param string $errorMsg
     * @param array $header
     * @return bool|void
     */
    public function sendErrorMessage(int $fd, int $errno, string $errorMsg, array $header)
    {
        $responseData = Application::buildResponseData($errno, $errorMsg);
        if (BaseServer::isPackLength()) {
            $payload = [$responseData, $header];
            $data = \Swoolefy\Rpc\RpcServer::pack($payload);
            return Swfy::getServer()->send($fd, $data);
        } else if (BaseServer::isPackEof()) {
            $text = \Swoolefy\Rpc\RpcServer::pack($responseData);
            return Swfy::getServer()->send($fd, $text);
        }
    }

    /**
     * filterHeader  filter头部空格
     * @param &$header
     * @return array
     */
    public function filterHeader(&$header)
    {
        foreach ($header as $key => &$value) {
            $header[$key] = trim($value);
        }
        return $header;
    }

    /**
     * encodePack 数据封包
     * usages:
     * $header = ['length'=>'','name'=>'bing'] 头部字段信息,'length'字段可以为空
     * @param mixed $data
     * @param array $header
     * @param array $headerStruct
     * @param string $packLengthKey
     * @param int $serializeType
     * @return string
     */
    public static function encodePack(
                $data,
         array  $header,
         array  $headerStruct = [],
         string $packLengthKey = 'length',
         int    $serializeType = self::DECODE_JSON
    )
    {
        $binHeaderData = '';
        $body = self::encode($data, $serializeType);
        /**
         * 如果没有设置，客户端的包头结构体与服务端一致
         */
        if (empty($headerStruct)) {
            $headerStruct = self::$headerStruct;
        }

        if (!isset($headerStruct[$packLengthKey])) {
            $headerStruct[$packLengthKey] = '';
        }

        foreach ($headerStruct as $key => $value) {
            if (isset($header[$key])) {
                // length packet header
                if ($key == $packLengthKey) {
                    $binHeaderData .= pack($value, strlen($body));
                } else {
                    // other packet header
                    $binHeaderData .= pack($value, $header[$key]);
                }
            }
        }
        return $binHeaderData . $body;
    }

    /**
     * encode 数据序列化
     * @param mixed $data
     * @param int $serializeType
     * @return string
     */
    public static function encode($data, int $serializeType = self::DECODE_JSON)
    {
        if (is_string($serializeType)) {
            $serializeType = self::SERIALIZE_TYPE[$serializeType];
        }

        switch ($serializeType) {
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
     * @param mixed $data
     * @param int  $unserializeType
     * @return mixed
     */
    public static function decode($data, $unserializeType = self::DECODE_JSON)
    {
        if (is_string($unserializeType)) {
            $unserializeType = self::SERIALIZE_TYPE[$unserializeType];
        }

        switch ($unserializeType) {
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
     * @param int $fd
     * @return bool
     */
    public function delete(int $fd)
    {
        unset($this->_buffers[$fd], $this->_headers[$fd]);
        return true;
    }

    /**
     * destroy 当workerStop时,删除缓冲的不完整的僵尸式数据包，并强制断开这些链接
     * @return bool
     */
    public function destroy()
    {
        if (!empty($this->_buffers)) {
            foreach ($this->_buffers as $fd => $data) {
                if ($this->server->exists($fd)) {
                    $this->server->close($fd, true);
                }
                unset($this->_buffers[$fd], $this->_headers[$fd]);
            }
            return true;
        }
    }
}
