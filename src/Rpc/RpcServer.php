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

include_once SWOOLEFY_CORE_ROOT_PATH . '/MainEventInterface.php';

use Swoole\Server;
use Swoolefy\Core\Swfy;
use Swoolefy\Tcp\TcpServer;
use Swoolefy\Core\RpcEventInterface;

abstract class RpcServer extends TcpServer implements RpcEventInterface
{
    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        self::buildPackHandler();
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart($server, $worker_id);

    /**
     * onConnect
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onConnect($server, $fd);

    /**
     * onReceive 接收数据时的回调处理，$data是一个完整的数据包，底层已经封装好，只需要配置好，直接使用即可
     * @param Server $server
     * @param int $fd
     * @param int $reactor_id
     * @param mixed $data
     * @return bool
     * @throws \Throwable
     */
    public function onReceive($server, $fd, $reactor_id, $data)
    {
        $appInstance = new RpcHandler(Swfy::getAppConf());
        $appInstance->run($fd, $data);
        return true;
    }

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return bool
     * @throws \Throwable
     */
    public function onTask($server, $task_id, $from_worker_id, $data, $task = null)
    {
        list($callable, $taskData, $fd) = $data;
        $appInstance = new RpcHandler(Swfy::getAppConf());
        $appInstance->run($fd, [$callable, $taskData], [$from_worker_id, $task_id, $task]);
        return true;
    }

    /**
     * onFinish
     * @param Server $server
     * @param $task_id
     * @param $data
     * @return mixed
     */
    abstract public function onFinish($server, $task_id, $data);

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage($server, $from_worker_id, $message);

    /**
     * onClose tcp
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose($server, $fd);

    /**
     * buildPackHandler 创建pack处理对象
     * @return void
     */
    protected function buildPackHandler()
    {
        if (self::isPackLength()) {
            $this->Pack = new Pack(self::$server);
            // packet_length_check
            $this->Pack->setHeaderStruct(self::$config['packet']['server']['pack_header_struct']);
            $this->Pack->setPackLengthKey(self::$config['packet']['server']['pack_length_key']);
            if (isset(self::$config['packet']['server']['serialize_type'])) {
                $this->Pack->setSerializeType(self::$config['packet']['server']['serialize_type']);
            }
            $this->Pack->setHeaderLength(self::$setting['package_body_offset']);
            if (isset(self::$setting['package_max_length'])) {
                $package_max_length = (int)self::$setting['package_max_length'];
                $this->Pack->setPacketMaxlength($package_max_length);
            }
        } else {
            $this->Text = new Text(self::$server);
            // packet_eof_check
            $this->Text->setPackEof(self::$setting['package_eof']);
            if (isset(self::$config['packet']['server']['serialize_type'])) {
                $serialize_type = self::$config['packet']['server']['serialize_type'];
            } else {
                $serialize_type = Text::DECODE_JSON;
            }
            $this->Text->setSerializeType($serialize_type);
        }
    }

    /**
     * isClientPackEof 根据设置判断客户端的分包方式
     * @return bool
     * @throws \Exception
     */
    final public static function isClientPackEof(): bool
    {
        if (!isset(self::$config['packet']['client']['pack_check_type'])) {
            throw new \Exception("you must set ['packet']['client']  in the config file");
        }
        if (in_array(self::$config['packet']['client']['pack_check_type'], ['eof', 'EOF'])) {
            return true;
        }
        return false;
    }

    /**
     * isClientPackLength 根据设置判断客户端的分包方式
     * @return bool
     * @throws \Exception
     */
    final public static function isClientPackLength()
    {
        if (self::isClientPackEof()) {
            return false;
        }
        return true;
    }

    /**
     * pack 根据配置设置,按照客户端的接受数据方式,打包数据发回给客户端
     * @param mixed $data
     * @return mixed
     * @throws \Exception
     */
    final public static function pack($data)
    {
        if (self::isClientPackLength()) {
            list($body_data, $header) = $data;
            $header_struct = self::$config['packet']['client']['pack_header_struct'];
            $pack_length_key = self::$config['packet']['client']['pack_length_key'];
            $serialize_type = self::$config['packet']['client']['serialize_type'];
            $header[$pack_length_key] = '';
            $pack_data = Pack::encodePack($body_data, $header, $header_struct, $pack_length_key, $serialize_type);
        } else {
            $eof = self::$config['packet']['client']['pack_eof'];
            $serialize_type = self::$config['packet']['client']['serialize_type'];
            if ($eof) {
                $pack_data = Text::encodePackEof($data, $serialize_type, $eof);
            } else {
                $pack_data = Text::encodePackEof($data, $serialize_type);
            }
        }
        return $pack_data;
    }

}
