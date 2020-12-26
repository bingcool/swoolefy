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

namespace Swoolefy\Mqtt;

use Simps\MQTT\Protocol;
use Simps\MQTT\Types;
use Swoolefy\Core\Swfy;

class MqttEvent {

    /**
     * @var \Swoole\Http\Server|\Swoole\Server|\Swoole\WebSocket\Server
     */
    protected $server;

    /**
     * @var integer
     */
    protected $fd;

    /**
     * @var array
     */
    protected $data;

    /**
     * @var array
     */
    public static $eventMaps = [
        Types::CONNECT => 'connect',
        Types::CONNACK => 'connectAck',
        Types::PUBLISH => 'publish',
        Types::PUBACK => 'pubAck',
        Types::PUBREC => 'pubRec',
        Types::PUBREL => 'pubRel',
        Types::PUBCOMP => 'pubComp',
        Types::SUBSCRIBE => 'subscribe',
        Types::SUBACK => 'subAck',
        Types::UNSUBSCRIBE => 'unSubscribe',
        Types::UNSUBACK => 'unSubscribeAck',
        Types::PINGREQ => 'pingReq',
        Types::PINGRESP => 'pingResp',
        Types::DISCONNECT => 'disconnect'
    ];

    /**
     * MqttEvent constructor.
     * @param $fd
     * @param $data
     */
    public function __construct($fd, $data)
    {
        $this->server = Swfy::getServer();
        $this->fd = $fd;
        $this->data = $data;
    }

    /**
     * @param $username
     * @param $password
     * @return bool
     */
    public function verify($username, $password): bool
    {
        // todo auth username and password
        return true;
    }

    /**
     * @param $protocol_name
     * @param $protocol_level
     * @param $username
     * @param $password
     * @param $client_id
     * @param $keep_alive
     * @param $clean_session
     * @param array $will
     * @return bool
     */
    public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $clean_session,
        array $will = []
    ): bool
    {
        // todo client_id与fd在connect的时候关联起来，保存好关系在redis
        return true;
    }

    public function disconnect(): bool
    {
        //todo client_id与fd在disconnect解除关联
        return true;
    }

    /**
     * @param $type
     * @param $topic
     * @param $message
     * @param $dup
     * @param $qos
     * @param $retain
     * @param $message_id
     */
    public function publish(
        $type,
        $topic,
        $message,
        $dup,
        $qos,
        $retain,
        $message_id
    )
    {
        // 循环发给订阅的客户端，这里要去除publish发布的连接端fd
        // 读取$message的client_id，client_id与fd在connect的时候关联起来，保存好关系在redis
        // 发布者可以通过向指定client_id发布消息，这时可以从关系中获取fd,从而向指定client_id发布消息
        foreach($this->server->connections as $sub_fd) {
            if($sub_fd != $this->fd) {
                $this->server->send(
                    $sub_fd,
                    Protocol::pack(
                        [
                            'type' => $type,
                            'topic' => $topic,
                            'message' => $message,
                            'dup' => $dup,
                            'qos' => $qos,
                            'retain' => $retain,
                            'message_id' => $message_id
                        ]
                    )
                );
            }
        }
    }

    /**
     * @param $type
     * @param $topics
     * @param $message_id
     */
    public function subscribe($type, $topics, $message_id)
    {
        //todo
    }

    /**
     * @param $type
     * @param $topics
     * @param $message_id
     */
    public function unSubscribe($type, $topics, $message_id)
    {
        //todo
    }

    /**
     * @param $clean_session
     */
    final public function connectAck($clean_session) {
        $this->server->send(
            $this->fd,
            Protocol::pack(
                [
                    'type' => Types::CONNACK,
                    'code' => 0,
                    'session_present' => $clean_session,
                ]
            )
        );
    }

    /**
     *
     */
    final public function pingReq()
    {
        $this->server->send($this->fd, Protocol::pack(['type' => Types::PINGRESP]));
    }

    /**
     * @param $message_id
     */
    final public function publishAck($message_id) {
        $this->server->send(
            $this->fd,
            Protocol::pack(
                [
                    'type' => Types::PUBACK,
                    'message_id' => $message_id ?? '',
                ]
            )
        );
    }

    /**
     * @param $message_id
     * @param $payload
     */
    final public function subscribeAck($message_id, $payload)
    {
        $this->server->send(
            $this->fd,
            Protocol::pack(
                [
                    'type' => Types::SUBACK,
                    'message_id' => $message_id ?? '',
                    'payload' => $payload
                ]
            )
        );
    }

    /**
     * @param $message_id
     */
    final public function unSubscribeAck($message_id)
    {
        $this->server->send(
            $this->fd,
            Protocol::pack(
                [
                    'type' => Types::UNSUBACK,
                    'message_id' => $message_id ?? '',
                ]
            )
        );
    }

}