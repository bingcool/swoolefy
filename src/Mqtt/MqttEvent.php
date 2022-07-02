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

use Swoolefy\Core\Swfy;
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Simps\MQTT\Message\ConnAck;
use Simps\MQTT\Message\PingResp;
use Simps\MQTT\Message\PubAck;
use Simps\MQTT\Message\SubAck;
use Simps\MQTT\Message\UnSubAck;

class MqttEvent
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
        Types::DISCONNECT => 'disconnect'
    ];

    /**
     * MqttEvent constructor.
     * @param int $fd
     * @param mixed $data
     * @return void
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

    /**
     * @return bool
     */
    public function disconnect(): bool
    {
        //todo client_id与fd在disconnect解除关联
        return true;
    }

    /**
     * @param $topic
     * @param $message
     * @param $dup
     * @param $qos
     * @param $retain
     * @param $message_id
     */
    public function publish(
        $topic,
        $message,
        $dup,
        $qos,
        $retain,
        $message_id
    )
    {
        // 循环发给订阅的客户端，这里要去除publish发布的连接端fd
        // 读取变量$message的client_id，client_id与fd在connect的时候关联起来，保存好关系在redis
        // 发布者可以通过向指定client_id发布消息，这时可以从关系中获取fd,从而向指定client_id发布消息
        foreach ($this->server->connections as $subFd) {
            $this->server->send(
                $subFd,
                Protocol\V3::pack(
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
    public function subscribe($type, $topics, $message_id)
    {
        //todo
    }

    /**
     * @param $type
     * @param $topics
     * @param $message_id
     * @return mixed
     */
    public function unSubscribe($type, $topics, $message_id)
    {
        //todo
    }

    /**
     * @param $clean_session
     * @return void
     */
    final public function connectAck($clean_session)
    {
        $this->server->send(
            $this->fd,
            (new ConnAck())->setCode(0)->setSessionPresent($clean_session)
        );
    }

    /**
     * pingReq
     * @return void
     */
    final public function pingReq()
    {
        $this->server->send($this->fd, (new PingResp()));
    }

    /**
     * @param $message_id
     * @return void
     */
    final public function publishAck($message_id)
    {
        $this->server->send(
            $this->fd,
            (new PubAck())->setMessageId($message_id ?? '')
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
            (new SubAck())->setMessageId($message_id ?? '')
                ->setCodes($payload)
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
            (new UnSubAck())->setMessageId($message_id ?? '')
        );
    }

}