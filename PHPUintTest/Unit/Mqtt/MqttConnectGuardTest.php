<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Mqtt;

use PHPUintTest\TestCase;
use ReflectionClass;
use Simps\MQTT\Protocol\V3 as ProtocolV3;
use Simps\MQTT\Protocol\V5 as ProtocolV5;
use Swoole\Server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Mqtt\MqttProtocolException;
use Swoolefy\Mqtt\MqttReceiveDispatcher;
use Swoolefy\Mqtt\MqttSessionManager;

/**
 * P0-01：未完成 CONNECT 的连接发送 PUBLISH/PUBREL 必须被拒绝并关闭，不能写入 retained 或路由。
 */
final class MqttConnectGuardTest extends TestCase
{
    /** @var mixed */
    private $prevConfig;

    /** @var mixed */
    private $prevServer;

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
        if (!class_exists(ProtocolV3::class) || !class_exists(ProtocolV5::class)) {
            $this->markTestSkipped('simps/mqtt is required for MQTT packet dispatch tests');
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

        $serverProp = $ref->getProperty('server');
        $serverProp->setAccessible(true);
        $this->prevServer = $serverProp->getValue();
        $closed = [];
        $serverProp->setValue(null, $this->newServer($closed));

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

        parent::tearDown();
    }

    public function testV3PublishBeforeConnectIsRejected(): void
    {
        $closed = [];
        $server = $this->newServer($closed);

        try {
            // PUBLISH QoS0 + retain，topic='t'，payload='m'
            MqttReceiveDispatcher::dispatch($server, 71, "\x31\x04\x00\x01tm", MQTT_PROTOCOL_LEVEL3);
            $this->fail('expected MqttProtocolException');
        } catch (MqttProtocolException $e) {
            $this->assertStringContainsString('not connected', $e->getMessage());
        }

        $this->assertSame([71], $closed);
        $this->assertNull(MqttSessionManager::getInstance()->get(71));
    }

    public function testV5PublishBeforeConnectIsRejected(): void
    {
        $closed = [];
        $server = $this->newServer($closed);

        try {
            // PUBLISH QoS0 + retain，topic='t'，properties 长度 0，payload='m'
            MqttReceiveDispatcher::dispatch($server, 72, "\x31\x05\x00\x01t\x00m", MQTT_PROTOCOL_LEVEL5);
            $this->fail('expected MqttProtocolException');
        } catch (MqttProtocolException $e) {
            $this->assertStringContainsString('not connected', $e->getMessage());
        }

        $this->assertSame([72], $closed);
        $this->assertNull(MqttSessionManager::getInstance()->get(72));
    }

    /**
     * @param list<int> $closed
     */
    private function newServer(array &$closed): Server
    {
        return new class($closed) extends Server {
            /** @param list<int> $closed */
            public function __construct(private array &$closed)
            {
            }

            public function exists(int $fd): bool
            {
                return true;
            }

            public function close(int $fd, bool $reset = false): bool
            {
                $this->closed[] = $fd;

                return true;
            }
        };
    }
}
