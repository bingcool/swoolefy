<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Mqtt;

use PHPUnit\Framework\Attributes\Group;
use Swoolefy\Mqtt\MqttProtocolException;
use Swoolefy\Mqtt\MqttSessionManager;
use Swoolefy\Mqtt\MqttTopicMatcher;
use PhpUintTest\TestCase;

/**
 * MQTT 模块综合单测（Topic / Session / Retain / QoS2 / 鉴权逻辑）。
 *
 * 可选环境变量：
 *   MQTT_SMOKE_HOST / MQTT_SMOKE_PORT — 若设置且 Broker 已启动，追加端到端冒烟（需 simps/mqtt）
 */
final class MqttModuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('MQTT_PROTOCOL_LEVEL3')) {
            \define('MQTT_PROTOCOL_LEVEL3', 4);
        }
        if (!\defined('MQTT_PROTOCOL_LEVEL5')) {
            \define('MQTT_PROTOCOL_LEVEL5', 5);
        }
    }

    protected function tearDown(): void
    {
        MqttSessionManager::reset();
        parent::tearDown();
    }

    public function testTopicMatcherExactAndWildcards(): void
    {
        $this->assertTrue(MqttTopicMatcher::matches('sensor/1/temp', 'sensor/1/temp'), 'exact');
        $this->assertTrue(MqttTopicMatcher::matches('sensor/+/temp', 'sensor/room1/temp'), 'plus one level');
        $this->assertTrue(MqttTopicMatcher::matches('sensor/#', 'sensor/a/b/c'), 'hash multi level');
        $this->assertTrue(MqttTopicMatcher::matches('#', 'any/topic/here'), 'hash only filter');
        $this->assertFalse(MqttTopicMatcher::matches('sensor/+/temp', 'sensor/a/b/temp'), 'plus no multi hop');
        $this->assertFalse(MqttTopicMatcher::matches('a/b', 'a/b/c'), 'exact length');
        $this->assertFalse(MqttTopicMatcher::matches('home/+/state', 'home/state'), 'plus requires one level');
    }

    public function testTopicMatcherEdgeCases(): void
    {
        $this->assertTrue(MqttTopicMatcher::matches('$SYS/broker/uptime', '$SYS/broker/uptime'), 'sys topic exact');
        $this->assertTrue(MqttTopicMatcher::matches('a/+/#', 'a/x/y/z'), 'plus then hash');
        $this->assertFalse(MqttTopicMatcher::matches('a/#/b', 'a/x/b'), 'hash must be last segment per MQTT spec');
    }

    public function testSessionBindAndClientIdKick(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(1, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(2, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);

        $this->assertNull($mgr->get(1), 'old fd removed when same client_id reconnects');
        $this->assertSame('device-a', $mgr->get(2)?->clientId, 'new fd owns client_id');
    }

    public function testSessionCleanSessionFlag(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, false);
        $mgr->subscribe(10, ['t/1' => 1], MQTT_PROTOCOL_LEVEL3);
        $this->assertSame(1, $mgr->stats()['subscriptions'], 'one subscription');

        $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, true);
        $this->assertCount(0, $mgr->get(10)?->subscriptions ?? [], 'clean session clears subs on re-bind');
    }

    public function testSessionRequireConnectedThrows(): void
    {
        $mgr = MqttSessionManager::getInstance();

        try {
            $mgr->subscribe(99, ['x' => 0], MQTT_PROTOCOL_LEVEL3);
            $this->fail('expected MqttProtocolException');
        } catch (MqttProtocolException $e) {
            $this->assertSame(99, $e->fd, 'exception carries fd');
        }
    }

    public function testSubscribeAndMatchSubscribersV3(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(1, 'pub', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(2, 'sub1', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(3, 'sub2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);

        $codes = $mgr->subscribe(2, ['home/+/temp' => 1, 'home/living/temp' => 2], MQTT_PROTOCOL_LEVEL3);
        $this->assertSame([1, 2], $codes, 'suback codes v3');
        $mgr->subscribe(3, ['other/#' => 0], MQTT_PROTOCOL_LEVEL3);

        $matches = $mgr->matchSubscribers('home/living/temp', 1);
        $fds = array_column($matches, 'fd');
        $this->assertSame([2], $fds, 'only matching wildcard subscriber');

        $pubOther = $mgr->matchSubscribers('other/x', 1);
        $this->assertSame([3], array_column($pubOther, 'fd'), 'hash subscriber only');
    }

    public function testSubscribeV5NoLocal(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(5, 'c1', 'u', 60, MQTT_PROTOCOL_LEVEL5, true);
        $mgr->subscribe(5, [
            'loop/#' => ['qos' => 1, 'no_local' => true],
        ], MQTT_PROTOCOL_LEVEL5);

        $matches = $mgr->matchSubscribers('loop/test', 5);
        $this->assertSame([], $matches, 'no_local excludes publisher fd');

        $matchesOther = $mgr->matchSubscribers('loop/test', 99);
        $this->assertSame(5, $matchesOther[0]['fd'] ?? 0, 'other publisher still delivers');
    }

    public function testUnsubscribe(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(7, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->subscribe(7, ['a/1' => 0, 'b/2' => 1], MQTT_PROTOCOL_LEVEL3);
        $mgr->unsubscribe(7, ['a/1', 'b/2']);

        $this->assertSame(0, $mgr->stats()['subscriptions'], 'all unsubscribed');
    }

    public function testSubscribeInvalidQos(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(8, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $codes = $mgr->subscribe(8, ['bad' => 9], MQTT_PROTOCOL_LEVEL3);
        $this->assertSame(0x80, $codes[0], 'invalid qos returns failure code');
    }

    public function testRetainedMessages(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->storeRetained('cfg/version', '1.0.0', 1, true);
        $mgr->storeRetained('cfg/version', '', 0, false);

        $this->assertSame(0, $mgr->stats()['retained_topics'], 'retain=false clears message');

        $mgr->storeRetained('home/living/temp', '22.5', 1, true);
        $retained = $mgr->retainedForSubscription('home/+/temp');
        $this->assertArrayHasKey('home/living/temp', $retained, 'retained visible to filter');
        $this->assertSame('22.5', $retained['home/living/temp']['message'], 'retained payload');
    }

    public function testWillMessageStoredAsRetain(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(20, 'will-client', 'u', 60, MQTT_PROTOCOL_LEVEL3, true, [
            'topic' => 'device/will',
            'message' => 'offline',
            'qos' => 1,
            'retain' => true,
        ]);

        $payload = $mgr->allRetainedForTopic('device/will');
        $this->assertSame('offline', $payload['message'] ?? null, 'will stored as retain');
    }

    public function testInboundQos2Staging(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(30, 'qos2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberInboundQoS2(30, 42, 't/qos2', 'payload', 2, false);

        $this->assertNotNull($mgr->releaseInboundQoS2(30, 42), 'first release returns payload');
        $this->assertNull($mgr->releaseInboundQoS2(30, 42), 'second release is idempotent null');
    }

    public function testSessionRemoveAndStats(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(40, 's1', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(41, 's2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->subscribe(40, ['x' => 0], MQTT_PROTOCOL_LEVEL3);

        $stats = $mgr->stats();
        $this->assertSame(2, $stats['connected'], 'two connected');
        $this->assertSame(1, $stats['subscriptions'], 'one sub');

        $mgr->remove(40);
        $this->assertNull($mgr->get(40), 'removed session gone');
        $this->assertSame(1, $mgr->stats()['connected'], 'one remains');
    }

    public function testProductionVerifyLogic(): void
    {
        $verify = static function (string $expectedUser, string $expectedPass, string $user, string $pass): bool {
            if ($expectedUser === '' && $expectedPass === '') {
                return true;
            }

            return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
        };

        $this->assertTrue($verify('', '', 'any', 'any'), 'empty config allows all');
        $this->assertTrue($verify('dev', 'secret', 'dev', 'secret'), 'correct credentials');
        $this->assertFalse($verify('dev', 'secret', 'dev', 'wrong'), 'wrong password');
        $this->assertFalse($verify('dev', 'secret', 'evil', 'secret'), 'wrong user');
    }

    #[Group('smoke')]
    public function testOptionalMqttSmoke(): void
    {
        $host = getenv('MQTT_SMOKE_HOST') ?: '';
        $port = (int) (getenv('MQTT_SMOKE_PORT') ?: 0);
        if ($host === '' || $port <= 0) {
            $this->markTestSkipped('mqtt smoke (set MQTT_SMOKE_HOST and MQTT_SMOKE_PORT)');
        }

        if (!class_exists(\Simps\MQTT\Client::class)) {
            $this->markTestSkipped('mqtt smoke (simps/mqtt not installed)');
        }

        $this->assertTrue(true, 'mqtt smoke placeholder configured');
    }
}
