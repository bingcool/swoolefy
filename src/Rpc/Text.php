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

class Text extends BaseParse
{
    /**
     * $pack_eof eof分包时设置
     * @var string
     */
    protected static $pack_eof = "\r\n\r\n";

    /**
     * $serialize_type 设置数据序列化的方式
     * @var string
     */
    protected static $serialize_type = 'json';

    /**
     * setPackEof
     * @param string $pack_eof
     */
    public function setPackEof(string $pack_eof = "\r\n\r\n")
    {
        self::$pack_eof = $pack_eof;
    }

    /**
     * setSerializeType
     * @param string $serialize_type
     */
    public function setSerializeType(string $serialize_type = 'json')
    {
        self::$serialize_type = $serialize_type;
    }

    /**
     * encodePackEof eof协议封包,包体中不能含有eof的结尾符号，否则出错
     * usages:
     *    Pack::$pack_eof = "\r\n\r\n";
     *    $Pack = new Pack();
     *    $sendData = $Pack->encodePackEof($data, Pack::DECODE_JSON);
     * @param mixed $data
     * @param int $seralize_type
     * @param string $eof
     * @return string
     */
    public static function encodePackEof($data, $serialize_type = self::DECODE_JSON, $eof = '')
    {
        if (empty($eof)) {
            $eof = self::$pack_eof;
        }
        $data = self::encode($data, $serialize_type) . $eof;
        return $data;
    }

    /**
     * decodePackEof  eof协议解包,每次收到一个完整的包
     * usages:
     *    Pack::$pack_eof = "\r\n\r\n";
     *    $Pack = new Pack();
     *    $res = $Pack->decodePackEof($data, Pack::DECODE_JSON);
     * @param string $data
     * @param int $unseralize_type
     * @return  mixed
     */
    public function decodePackEof($data, $unserialize_type = '')
    {
        if ($unserialize_type) {
            self::$serialize_type = $unserialize_type;
        }
        return self::decode($data, self::$serialize_type);
    }

    /**
     * encode 数据序列化
     * @param mixed $data
     * @param int $serialize_type
     * @return  string
     */
    public static function encode($data, $serialize_type = self::DECODE_JSON)
    {
        if (is_string($serialize_type)) {
            $serialize_type = self::SERIALIZE_TYPE[$serialize_type];
        }
        switch ($serialize_type) {
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
     * @param string $data
     * @param mixed $unseralize_type
     * @return   mixed
     */
    public static function decode($data, $unserialize_type = self::DECODE_JSON)
    {
        if (is_string($unserialize_type)) {
            $unserialize_type = self::SERIALIZE_TYPE[$unserialize_type];
        }
        switch ($unserialize_type) {
            // json
            case 1:
                return json_decode($data, true);
            default:
                // serialize
                return unserialize($data);
        }
    }


}