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

namespace Swoolefy\Core;

class ResponseFormatter
{
    /**
     * 定义响应的数据格式
     * @param int $ret
     * @param string $msg
     * @param string $data
     * @return array
     */
    public static function formatterData(int $ret = 0, string $msg = '', $data = '')
    {
        return [
            'ret'  => $ret,
            'msg'  => $msg,
            'data' => $data
            //'require_id' => 'xxxxxxxxx'
        ];
    }
}