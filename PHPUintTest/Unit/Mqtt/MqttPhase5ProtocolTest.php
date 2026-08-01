<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Mqtt;

use PHPUintTest\TestCase;
use ReflectionClass;
use Swoolefy\Core\BaseServer;
use Swoolefy\Mqtt\MqttProtocolException;
use Swoolefy\Mqtt\MqttReceiveDispatcher;
use Swoolefy\Mqtt\MqttSessionManager;

/**
 * 阶段五 7.2～7.4（审计项 20、29、30）：MQTT QoS2 限额、auto_protocol Session、keep_alive。
 */
final class MqttPhase5ProtocolTest extends TestCase
{
    /** @var mixed */
    private $prevConfig;

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
        $ref = new ReflectionClass(BaseServer::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $this->prevConfig = $prop->getValue();
        MqttSessionManager::reset();
    }

    protected function tearDown(): void
    {
        MqttSessionManager::reset();
        $ref = new ReflectionClass(BaseServer::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->prevConfig);
        parent::tearDown();
    }

    /**
     * 测 QoS2 pending：正常暂存/PUBREL 清理、重复 message_id 覆盖、条数与字节超限。
     * 对应问题：无上限 pending 可被恶意客户端打爆 Worker 内存。
     */
    public function testQos2PendingLimitsAndRelease(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->configureQos2PendingLimits([
            'max_count' => 2,
            'max_bytes' => 20,
            'ttl' => 60,
        ]);
        $mgr->bind(1, 'c1', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);

        $mgr->rememberInboundQoS2(1, 1, 't/a', '12345', 2, false);
        // 重复 message_id：覆盖，不额外占条数
        $mgr->rememberInboundQoS2(1, 1, 't/a', 'abc', 2, false);
        $mgr->rememberInboundQoS2(1, 2, 't/b', 'xy', 2, false);

        try {
            $mgr->rememberInboundQoS2(1, 3, 't/c', 'z', 2, false);
            $this->fail('expected count exceeded');
        } catch (MqttProtocolException $e) {
            $this->assertStringContainsString('count exceeded', $e->getMessage());
        }

        $mgr->releaseInboundQoS2(1, 2);
        try {
            $mgr->rememberInboundQoS2(1, 4, 't/d', str_repeat('x', 30), 2, false);
            $this->fail('expected bytes exceeded');
        } catch (MqttProtocolException $e) {
            $this->assertStringContainsString('bytes exceeded', $e->getMessage());
        }

        $released = $mgr->releaseInboundQoS2(1, 1);
        $this->assertSame('abc', $released['message'] ?? null);
        $this->assertNull($mgr->releaseInboundQoS2(1, 1));
    }

    /**
     * 测 QoS2 pending TTL：过期条目被 cleanupExpiredQos2Pending 清理。
     * 对应问题：从未 PUBREL 的 pending 会永久残留。
     */
    public function testQos2PendingTtlCleanup(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->configureQos2PendingLimits(['max_count' => 10, 'max_bytes' => 1024, 'ttl' => 30]);
        $mgr->bind(2, 'c2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberInboundQoS2(2, 9, 't', 'p', 2, false);

        $session = $mgr->get(2);
        $this->assertNotNull($session);
        $session->inboundQoS2[9]['created_at'] = time() - 120;

        $removed = $mgr->cleanupExpiredQos2Pending(time());
        $this->assertSame(1, $removed);
        $this->assertArrayNotHasKey(9, $session->inboundQoS2);
    }

    /**
     * 测 auto_protocol：已连接 Session 固定协议级别；未连接非 CONNECT 报错。
     * 对应问题：后续报文仍按 conf 默认解码，V3/V5 交错会解错。
     */
    public function testAutoProtocolUsesSessionLevelAfterConnect(): void
    {
        $this->setMqttConf([
            'auto_protocol' => true,
            'protocol_level' => MQTT_PROTOCOL_LEVEL3,
        ]);

        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(10, 'v5', 'u', 60, MQTT_PROTOCOL_LEVEL5, true);
        $mgr->bind(11, 'v3', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);

        // raw 内容在已连接路径不会被 peek，占位即可
        $this->assertSame(
            MQTT_PROTOCOL_LEVEL5,
            MqttReceiveDispatcher::resolveReceiveProtocolLevel(10, 'x')
        );
        $this->assertSame(
            MQTT_PROTOCOL_LEVEL3,
            MqttReceiveDispatcher::resolveReceiveProtocolLevel(11, 'x')
        );

        $this->expectException(MqttProtocolException::class);
        MqttReceiveDispatcher::resolveReceiveProtocolLevel(99, 'not-connect');
    }

    /**
     * 测 keep_alive：活跃连接不误断；超过 1.5 倍关闭；keep_alive=0 跳过。
     * 对应问题：仅依赖 Swoole 通用 heartbeat，未执行 MQTT keep_alive 语义。
     */
    public function testKeepAliveTimeoutAndZeroDisabled(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(20, 'alive', 'u', 10, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(21, 'stale', 'u', 10, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(22, 'disabled', 'u', 0, MQTT_PROTOCOL_LEVEL3, true);

        $now = time();
        $mgr->get(20)?->touch($now);
        $mgr->get(21)?->touch($now - 20); // 10 * 1.5 = 15，已超时
        $mgr->get(22)?->touch($now - 1000);

        $closed = [];
        $server = new class($closed) {
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

        $count = $mgr->closeKeepAliveTimeouts($server, $now);
        $this->assertSame(1, $count);
        $this->assertSame([21], $closed);
        $this->assertNotNull($mgr->get(20));
        $this->assertNull($mgr->get(21));
        $this->assertNotNull($mgr->get(22), 'keep_alive=0 must not be closed by this rule');
    }

    /**
     * @param array<string, mixed> $mqtt
     */
    private function setMqttConf(array $mqtt): void
    {
        $ref = new ReflectionClass(BaseServer::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue(null, ['mqtt' => $mqtt]);
    }
}
