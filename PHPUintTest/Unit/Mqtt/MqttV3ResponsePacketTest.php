<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Mqtt;

use PHPUintTest\TestCase;
use ReflectionClass;
use Simps\MQTT\Protocol\Types;
use Simps\MQTT\Protocol\V3 as ProtocolV3;
use Swoole\Server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
use Swoolefy\Mqtt\MqttSessionManager;
use Swoolefy\Mqtt\ProductionMqttEventV3;

/**
 * 验证 V3 响应报文在 simps/mqtt 2.x（严格 int 参数）下可正常打包。
 *
 * 覆盖 CONNACK / PUBACK / SUBACK / UNSUBACK 以及出站 PUBLISH 的 dup/retain 标志。
 */
final class MqttV3ResponsePacketTest extends TestCase
{
    /** @var list<array{fd:int,data:string}> */
    private array $sent = [];

    /** @var mixed */
    private $prevConfig;

    /** @var mixed */
    private $prevServer;

    /** @var mixed */
    private $prevSwfyServer;

    public static function setUpBeforeClass(): void
    {
        if (!\defined('MQTT_PROTOCOL_LEVEL3')) {
            \define('MQTT_PROTOCOL_LEVEL3', 4);
        }
        if (!\defined('MQTT_PROTOCOL_LEVEL5')) {
            \define('MQTT_PROTOCOL_LEVEL5', 5);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(ProtocolV3::class)) {
            $this->markTestSkipped('simps/mqtt is required for MQTT packet tests');
        }

        $ref = new ReflectionClass(BaseServer::class);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $this->prevConfig = $configProp->getValue();
        $configProp->setValue(null, [
            'app_conf' => ['components' => []],
            'mqtt' => [
                'protocol_level' => MQTT_PROTOCOL_LEVEL3,
                'auto_protocol' => false,
            ],
        ]);

        $this->sent = [];
        $serverProp = $ref->getProperty('server');
        $serverProp->setAccessible(true);
        $this->prevServer = $serverProp->getValue();
        $server = $this->newServer();
        $serverProp->setValue(null, $server);

        // MqttEventV3 构造时取 Swfy::getServer()；全量套件里前面的测试可能留下 stdClass 占位
        $this->prevSwfyServer = Swfy::getServer();
        Swfy::setSwooleServer($server);

        MqttSessionManager::reset();
    }

    protected function tearDown(): void
    {
        MqttSessionManager::reset();

        $ref = new ReflectionClass(BaseServer::class);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $configProp->setValue(null, $this->prevConfig);

        $serverProp = $ref->getProperty('server');
        $serverProp->setAccessible(true);
        $serverProp->setValue(null, $this->prevServer);
        Swfy::setSwooleServer($this->prevSwfyServer);

        parent::tearDown();
    }

    public function testConnectAckAndRejectPackets(): void
    {
        $event = new ProductionMqttEventV3(1, []);

        $event->connectAck(false);
        $event->connectReject(5);

        $this->assertCount(2, $this->sent);

        $connAck = ProtocolV3::unpack($this->sent[0]['data']);
        $this->assertSame(Types::CONNACK, $connAck['type']);
        $this->assertSame(0, $connAck['code']);
        $this->assertSame(0, $connAck['session_present']);

        $reject = ProtocolV3::unpack($this->sent[1]['data']);
        $this->assertSame(Types::CONNACK, $reject['type']);
        $this->assertSame(5, $reject['code']);
        $this->assertSame(0, $reject['session_present']);
    }

    public function testPubSubAckPackets(): void
    {
        $event = new ProductionMqttEventV3(1, []);

        $event->publishAck(7);
        $event->subscribeAck(8, [0, 1]);
        $event->unSubscribeAck(9);

        $this->assertCount(3, $this->sent);

        $pubAck = ProtocolV3::unpack($this->sent[0]['data']);
        $this->assertSame(Types::PUBACK, $pubAck['type']);
        $this->assertSame(7, $pubAck['message_id']);

        $subAck = ProtocolV3::unpack($this->sent[1]['data']);
        $this->assertSame(Types::SUBACK, $subAck['type']);
        $this->assertSame(8, $subAck['message_id']);
        $this->assertSame([0, 1], $subAck['codes']);

        $unSubAck = ProtocolV3::unpack($this->sent[2]['data']);
        $this->assertSame(Types::UNSUBACK, $unSubAck['type']);
        $this->assertSame(9, $unSubAck['message_id']);
    }

    public function testOutboundPublishUsesIntFlags(): void
    {
        $manager = MqttSessionManager::getInstance();
        $manager->bind(12, 'subscriber', '', 30, MQTT_PROTOCOL_LEVEL3, true);
        $manager->subscribe(12, ['compat/#' => 0], MQTT_PROTOCOL_LEVEL3);

        $event = new ProductionMqttEventV3(11, []);
        $event->publish('compat/topic', 'payload', false, 0, true, 0);

        $this->assertCount(1, $this->sent);
        $this->assertSame(12, $this->sent[0]['fd']);

        $packet = ProtocolV3::unpack($this->sent[0]['data']);
        $this->assertSame(Types::PUBLISH, $packet['type']);
        $this->assertSame('compat/topic', $packet['topic']);
        $this->assertSame('payload', $packet['message']);
        $this->assertSame(0, (int) $packet['dup']);
        $this->assertSame(1, (int) $packet['retain']);

        $retained = $manager->retainedForSubscription('compat/#');
        $this->assertArrayHasKey('compat/topic', $retained);
        $this->assertSame('payload', $retained['compat/topic']['message']);
    }

    private function newServer(): Server
    {
        return new class($this->sent) extends Server {
            /** @param list<array{fd:int,data:string}> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function exists(int $fd): bool
            {
                return true;
            }

            public function send(int|string $fd, string $data, int $serverSocket = -1): bool
            {
                $this->sent[] = ['fd' => (int) $fd, 'data' => $data];

                return true;
            }
        };
    }
}
