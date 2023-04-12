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

use Swoolefy\Rpc\RpcServer;
use Swoolefy\Udp\UdpHandler;
use Swoolefy\Exception\SystemException;

class BService extends BaseObject
{

    use \Swoolefy\Core\ServiceTrait;

    /**
     * fd
     * @var int
     */
    protected $fd;

    /**
     * appConf
     * @var array
     */
    protected $appConf = [];

    /**
     * @var mixed
     */
    private $mixedParams;

    /**
     * @var mixed
     */
    protected $clientInfo;

    /**
     * __construct
     */
    public function __construct()
    {
        /**
         * @var Swoole $app
         */
        $app            = Application::getApp();
        $this->fd       = $app->getFd();
        $this->appConf  = $app->getAppConf();

        if (BaseServer::isUdpApp()) {
            /** @var UdpHandler $app */
            $this->clientInfo = $app->getClientInfo();
        }

        if (\Swoole\Coroutine::getCid() >=0 ) {
            \Swoole\Coroutine::defer(function () {
                $this->defer();
            });
        }
    }

    /**
     * beforeAction
     * @param string $action
     * @return bool
     */
    public function _beforeAction(string $action): bool
    {
        return true;
    }


    /**
     * @param mixed $mixedParams
     * @return void
     */
    public function setMixedParams($mixedParams)
    {
        if(empty($this->mixedParams)) {
            $this->mixedParams = $mixedParams;
        }
    }

    /**
     * @return mixed
     */
    public function getMixedParams()
    {
        return $this->mixedParams;
    }

    /**
     * return tcp
     * @param int $fd
     * @param mixed $data
     * @param array $header
     * @return mixed
     */
    public function send(int $fd, $data, array $header = [])
    {
        if (!BaseServer::isRpcApp()) {
            throw new SystemException("BService::send() this method only can be called by tcp or rpc server!");
        }

        if (BaseServer::isPackLength()) {
            $payload = [$data, $header];
            $data = \Swoolefy\Rpc\RpcServer::pack($payload);
            return Swfy::getServer()->send($fd, $data);
        } else if (BaseServer::isPackEof()) {
            $text = \Swoolefy\Rpc\RpcServer::pack($data);
            return Swfy::getServer()->send($fd, $text);
        }

    }

    /**
     * sendTo udp
     * @param $data
     * @param string $ip
     * @param string $port
     * @param int $serverSocket
     * @return mixed
     */
    public function sendTo($data, string $ip = '', $port = '', int $serverSocket = -1)
    {
        if (empty($ip)) {
            $ip = $this->clientInfo['address'];
        }

        if (empty($port)) {
            $port = $this->clientInfo['port'];
        }

        if (!BaseServer::isUdpApp()) {
            throw new SystemException("BService::sendTo() this method only can be called by udp server!");
        }

        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        return Swfy::getServer()->sendto($ip, $port, $data, $serverSocket);
    }

    /**
     * push websocket
     * @param int $fd
     * @param mixed $data
     * @param int $opcode
     * @param int $finish
     * @return bool
     */
    public function push(int $fd, $data, int $opcode = 1, int $finish = SWOOLE_WEBSOCKET_FLAG_FIN)
    {
        if (!BaseServer::isWebsocketApp()) {
            throw new SystemException("BService::push() this method only can be called by websocket server!");
        }

        if (!Swfy::getServer()->isEstablished($fd)) {
            throw new SystemException("Websocket connection closed");
        }

        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        $result = Swfy::getServer()->push($fd, $data, $opcode, $finish);
        return $result;

    }

    /**
     * isClientPackEof  根据设置判断客户端的分包方式eof
     * @return bool
     */
    public function isClientPackEof(): bool
    {
        return RpcServer::isClientPackEof();
    }

    /**
     * isClientPackLength 根据设置判断客户端的分包方式length
     * @return bool
     */
    public function isClientPackLength(): bool
    {
        if ($this->isClientPackEof()) {
            return false;
        }
        return true;
    }

    /**
     * getRpcPackHeader  获取rpc的pack头信息,只适用于rpc服务
     * @return array
     */
    public function getRpcPackHeader()
    {
        return Application::getApp()->getRpcPackHeader();
    }

    /**
     * getRpcPackBodyParams 获取rpc的包体数据
     * @return array
     */
    public function getRpcPackBodyParams()
    {
        return Application::getApp()->getRpcPackBodyParams();
    }

    /**
     * getUdpData 获取udp的数据
     * @return mixed
     */
    public function getUdpData()
    {
        return Application::getApp()->getUdpData();
    }

    /**
     * getWebsocketMsg 获取websocket的信息
     * @return mixed
     */
    public function getWebsocketMsg()
    {
        return Application::getApp()->getWebsocketMsg();
    }


    /**
     * @return mixed
     */
    public function getClientInfo()
    {
        return $this->clientInfo;
    }

    /**
     * afterAction
     * @param string $action
     * @return mixed
     */
    public function _afterAction(string $action)
    {

    }

    /**
     * defer service实例协程销毁前可以做初始化一些静态变量
     * @return mixed
     */
    public function defer()
    {
    }

}