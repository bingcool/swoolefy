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


class RpcClientConst
{
    // 服务器连接失败
    const ERROR_CODE_CONNECT_FAIL = 5001;
    // 首次数据发送成功
    const ERROR_CODE_SEND_SUCCESS = 5002;
    // 二次发送成功
    const ERROR_CODE_SECOND_SEND_SUCCESS = 5003;
    // 当前数据不属于当前的请求client对象
    const ERROR_CODE_NO_MATCH = 5004;
    // 数据接收超时,一般是服务端出现阻塞或者其他问题
    const ERROR_CODE_CALL_TIMEOUT = 5005;
    // callable的解析出错
    const ERROR_CODE_CALLABLE = 5006;
    // enpack的解析出错,一般是serialize_type设置错误造成
    const ERROR_CODE_ENPACK = 5007;
    // depack的解析出错,一般是serialize_type设置错误造成
    const ERROR_CODE_DEPACK = 5008;
    // 数据返回成功
    const ERROR_CODE_SUCCESS = 0;

    // 接收数据方式(调用方式)
    const WAIT_RECV = 'waitRecv';
    const MULTI_RECV = 'multiRecv';
}