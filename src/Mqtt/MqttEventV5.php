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

namespace Swoolefy\Mqtt;

use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Swoolefy\Core\Swfy;

abstract class MqttEventV5
{

    /**
     * @var \Swoole\Http\Server|\Swoole\Server|\Swoole\WebSocket\Server
     */
    protected $server;

    /**
     * @var int
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
        Types::DISCONNECT => 'disconnect',
        Types::AUTH => 'auth'
    ];

    /**
     * MqttEvent constructor.
     * @param int $fd
     * @param mixed $data
     */
    public function __construct(int $fd, mixed $data)
    {
        $this->server = Swfy::getServer();
        $this->fd = $fd;
        $this->data = $data;
    }

    /**
     * @param $username
     * @param $password
     * @param $authenticationMethod
     * @param $authenticationData
     * @return bool
     */
    abstract public function verify(
        $username,
        $password,
        $authentication_method,
        $authentication_data
    ): bool;

    /**
     * @param $code
     * @param array $properties
     * @return mixed
     */
    abstract public function auth($code, array $properties);

    /**
     * @param $protocol_name
     * @param $protocol_level
     * @param $username
     * @param $password
     * @param $client_id
     * @param $keep_alive
     * @param $properties
     * @param $clean_session
     * @param array $will
     * @return bool
     */
    abstract public function connect(
        $protocol_name,
        $protocol_level,
        $username,
        $password,
        $client_id,
        $keep_alive,
        $properties,
        $clean_session,
        array $will = []
    ): bool;

    /**
     * @return bool
     */
    abstract public function disconnect();

    /**
     * @param $topic
     * @param $message
     * @param $dup
     * @param $qos
     * @param $retain
     * @param $message_id
     * @return mixed
     */
    public function publish(
        $topic,
        $message,
        $dup,
        $qos,
        $retain,
        $message_id
    ) {
        // 循环发给订阅的客户端，这里要去除publish发布的连接端fd
        // 读取$message的client_id，client_id与fd在connect的时候关联起来，保存好关系在redis
        // 发布者可以通过向指定client_id发布消息，这时可以从关系中获取fd,从而向指定client_id发布消息
        foreach ($this->server->connections as $subFd) {
            $this->server->send(
                $subFd,
                Protocol\V5::pack(
                    [
                        'type' => Types::PUBLISH,
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

    /**
     * @param $type
     * @param $topics
     * @param $message_id
     * @return mixed
     */
    abstract public function subscribe($type, $topics, $message_id);

    /**
     * @param $type
     * @param $topics
     * @param $message_id
     * @return mixed
     */
    abstract public function unSubscribe($type, $topics, $message_id);

    /**
     * @param $clean_session
     * @return void
     */
    public function connectAck($clean_session, array $properties = [])
    {
        $properties = array_merge([
            'maximum_packet_size' => 1048576,
            'retain_available' => true,
            'shared_subscription_available' => true,
            'subscription_identifier_available' => true,
            'topic_alias_maximum' => 65535,
            'wildcard_subscription_available' => true,
        ], $properties);

        $this->server->send(
            $this->fd,
            Protocol\V5::pack(
                [
                    'type' => Types::CONNACK,
                    'code' => 0,
                    'session_present' => $clean_session,
                    'properties' => $properties
                ]
            )
        );
    }

    /**
     * pingReq
     * @return void
     */
    final public function pingReq()
    {
        $this->server->send($this->fd, Protocol\V5::pack(['type' => Types::PINGRESP]));
    }

    /**
     * @param $message_id
     * @return void
     */
    final public function publishAck($message_id)
    {
        $this->server->send(
            $this->fd,
            Protocol\V5::pack(
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
     * @return void
     */
    final public function subscribeAck($message_id, $payload)
    {
        $this->server->send(
            $this->fd,
            Protocol\V5::pack(
                [
                    'type' => Types::SUBACK,
                    'message_id' => $message_id ?? '',
                    'codes' => $payload,
                ]
            )
        );
    }

    /**
     * @param $message_id
     * @return void
     */
    final public function unSubscribeAck($message_id)
    {
        $this->server->send(
            $this->fd,
            Protocol\V5::pack(
                [
                    'type' => Types::UNSUBACK,
                    'message_id' => $message_id ?? '',
                ]
            )
        );
    }

}