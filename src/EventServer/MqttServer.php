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

namespace protocol\mqtt;

use Swoolefy\Core\Swfy;

class MqttServer extends \Swoolefy\Mqtt\MqttServer {

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config=[]) {
        parent::__construct($config);
    }

    /**
     * onWorkerStart
     * @param  object $server
     * @param  int    $worker_id
     * @return void
     */
    public function onWorkerStart($server, $worker_id) {}

    /**
     * onConnect socket连接上时回调函数
     * @param  object $server
     * @param  int    $fd
     * @return void
     */
    public function onConnect($server, $fd) {}

    /**
     * onFinish
     * @param  object $server
     * @param  int    $task_id
     * @param  mixed  $data
     * @return mixed
     */
    public function onFinish($server, $task_id, $data) {}

    /**
     * onPipeMessage
     * @param object $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage($server, $from_worker_id, $message) {}

    /**
     * onClose tcp连接关闭时回调函数
     * @param  object $server
     * @param  int    $fd
     * @return void
     */
    public function onClose($server, $fd) {}

}
