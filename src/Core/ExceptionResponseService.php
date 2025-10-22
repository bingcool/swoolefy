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

class ExceptionResponseService extends BService
{
    /**
     * error response
     * @param string $msg
     * @return mixed
     */
    public function errorMsg(string $msg, int $code = -1)
    {
        $responseDataDto = ResponseFormatter::formatDataDto($code, $msg);
        if (BaseServer::isRpcApp()) {
            $isSamePacketStruct = $this->serverClientPacketStructSame();
            if ($isSamePacketStruct) {
                $fd = Application::getApp()->getFd();
                $header = $this->getRpcPackHeader();
                $this->send($fd, $responseDataDto, $header);
            }
        } else if (BaseServer::isWebsocketApp()) {
            $fd = Application::getApp()->getFd();
            $this->push($fd, $responseDataDto, $opcode = 1, $finish = true);
        }
        return $responseDataDto;
    }

    /**
     * serverClientPacketStructSame 头部结构体是否相同，相同才能直接获取返回，否则要根据client端header_struct的定义生产header头部信息
     * @return bool
     */
    protected function serverClientPacketStructSame()
    {
        $conf = Swfy::getConf();
        $serverPackHeaderStruct = $conf['packet']['server']['pack_header_struct'];
        $clientPackHeaderStruct = $conf['packet']['client']['pack_header_struct'];
        if (is_array($serverPackHeaderStruct) && is_array($clientPackHeaderStruct)) {
            $serverNum = count(array_keys($serverPackHeaderStruct));
            $clientNum = count(array_keys($clientPackHeaderStruct));
            if ($serverNum == $clientNum) {
                $isSamePacketStruct = true;
                foreach ($serverPackHeaderStruct as $k => $value) {
                    if ($clientPackHeaderStruct[$k] != $value) {
                        $isSamePacketStruct = false;
                    }
                }
                return $isSamePacketStruct;
            }
        }
        return false;
    }
}