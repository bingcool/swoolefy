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

namespace Swoolefy\Library\Db;

class Util {

    /** 将Pg的整型数组类型字符串转为php数组
     * eg {123,456,789} -> [123,456,789]
     * @param $string
     * @param bool $valueIsString
     * @return array|mixed
     */
    public static function trimArray($string, $valueIsString = false)
    {
        if($valueIsString) {
            $string = str_replace(['{', '}'], ['', ''], $string);
            $items = explode(',', $string);
            $array = array_map(function ($item) {
                if(is_numeric($item)) {
                    $item = (int)$item;
                }
                return $item;
            }, $items);

            return empty($string) ? [] : $array;
        }
        $string = str_replace(['{', '}'], ['[', ']'], $string);
        return json_decode($string, true);
    }

    /**
     * 将php的数组格式为符合Pg的整型数组类型字符串
     * @param array $data
     * @return string
     */
    public static function formatArray(array $data)
    {
        if (empty($data)) {
            return '{}';
        }
        $data = array_filter($data, function ($item) {
            return !($item === '' || is_null($item));
        });
        return  "{" . implode(',', $data) . "}";
    }

    /**
     * php的关联数组转Pg的jsonb
     * @param $data
     * @return string
     */
    public static function formatToJson(array $data)
    {
        $result =  is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : '{}';
        return $result;
    }
}