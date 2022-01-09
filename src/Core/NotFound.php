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

namespace Swoolefy\Core;

class NotFound extends BService
{
    /**
     * error404
     * @param string $class
     * @return mixed
     * @throws \Exception
     */
    public function error404(string $class)
    {
        $ret = 404;
        $msg = sprintf(
            "Not Found Class %s",
            $class
        );
        $responseData = Application::buildResponseData($ret, $msg);
        if (BaseServer::isRpcApp()) {
            $is_same_packet_struct = $this->serverClientPacketStructSame();
            if ($is_same_packet_struct) {
                $fd = Application::getApp()->getFd();
                $header = $this->getRpcPackHeader();
                $this->send($fd, $responseData, $header);
            }

        } else if (BaseServer::isWebsocketApp()) {
            $fd = Application::getApp()->getFd();
            $this->push($fd, $responseData, $opcode = 1, $finish = true);
        }
        return $responseData;
    }

    /**
     * error500
     * @param string $class
     * @param string $action
     * @return mixed
     * @throws \Exception
     */
    public function error500(string $class, string $action)
    {
        $ret = 500;
        $msg = sprintf(
            "Call Undefined Method Of %s::%s",
            $class,
            $action
        );
        $responseData = Application::buildResponseData($ret, $msg);
        if (BaseServer::isRpcApp()) {
            $is_same_packet_struct = $this->serverClientPacketStructSame();
            if ($is_same_packet_struct) {
                $fd = Application::getApp()->getFd();
                $header = $this->getRpcPackHeader();
                $this->send($fd, $responseData, $header);
            }
        } else if (BaseServer::isWebsocketApp()) {
            $fd = Application::getApp()->getFd();
            $this->push($fd, $responseData, $opcode = 1, $finish = true);
        }
        return $responseData;
    }

    /**
     * error
     * @param string $msg
     * @return mixed
     * @throws \Exception
     */
    public function errorMsg(string $msg, int $code = 500)
    {
        $ret = $code;
        $responseData = Application::buildResponseData($ret, $msg);
        if (BaseServer::isRpcApp()) {
            $is_same_packet_struct = $this->serverClientPacketStructSame();
            if ($is_same_packet_struct) {
                $fd = Application::getApp()->getFd();
                $header = $this->getRpcPackHeader();
                $this->send($fd, $responseData, $header);
            }

        } else if (BaseServer::isWebsocketApp()) {
            $fd = Application::getApp()->getFd();
            $this->push($fd, $responseData, $opcode = 1, $finish = true);
        }
        return $responseData;
    }

    /**
     * serverClientPacketStructSame 头部结构体是否相同，相同才能直接获取返回，否则要根据client端header_struct的定义生产header头部信息
     * @return boolean
     */
    protected function serverClientPacketStructSame()
    {
        $conf = Swfy::getConf();
        $server_pack_header_struct = $conf['packet']['server']['pack_header_struct'];
        $client_pack_header_struct = $conf['packet']['client']['pack_header_struct'];
        if (is_array($server_pack_header_struct) && is_array($client_pack_header_struct)) {
            $server_num = count(array_keys($server_pack_header_struct));
            $client_num = count(array_keys($client_pack_header_struct));
            if ($server_num == $client_num) {
                $is_same_packet_struct = true;
                foreach ($server_pack_header_struct as $k => $value) {
                    if ($client_pack_header_struct[$k] != $value) {
                        $is_same_packet_struct = false;
                    }
                }
                return $is_same_packet_struct;
            }
        }
        return false;
    }
}