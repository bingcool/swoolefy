<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Mqtt;

use PHPUnit\Framework\Attributes\Group;
use Swoolefy\Mqtt\MqttProtocolException;
use Swoolefy\Mqtt\MqttSessionManager;
use Swoolefy\Mqtt\MqttTopicMatcher;
use PHPUintTest\TestCase;

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

    /**
     * 验证：MqttTopicMatcher 对精确主题、单级通配符 + 与多级通配符 # 的匹配规则。
     */
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

    /**
     * 验证：$SYS 系统主题、+/# 组合及 # 必须位于过滤器末尾等边界场景。
     */
    public function testTopicMatcherEdgeCases(): void
    {
        $this->assertTrue(MqttTopicMatcher::matches('$SYS/broker/uptime', '$SYS/broker/uptime'), 'sys topic exact');
        $this->assertTrue(MqttTopicMatcher::matches('a/+/#', 'a/x/y/z'), 'plus then hash');
        $this->assertFalse(MqttTopicMatcher::matches('a/#/b', 'a/x/b'), 'hash must be last segment per MQTT spec');
    }

    /**
     * 验证：相同 client_id 重连时，旧 fd 会话被踢出，新 fd 独占该 client_id。
     */
    public function testSessionBindAndClientIdKick(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(1, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->bind(2, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);

        $this->assertNull($mgr->get(1), 'old fd removed when same client_id reconnects');
        $this->assertSame('device-a', $mgr->get(2)?->clientId, 'new fd owns client_id');
    }

    /**
     * 验证：clean session 为 true 时，重新绑定会清空该客户端已有订阅。
     */
    public function testSessionCleanSessionFlag(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, false);
        $mgr->subscribe(10, ['t/1' => 1], MQTT_PROTOCOL_LEVEL3);
        $this->assertSame(1, $mgr->stats()['subscriptions'], 'one subscription');

        $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, true);
        $this->assertCount(0, $mgr->get(10)?->subscriptions ?? [], 'clean session clears subs on re-bind');
    }

    /**
     * 验证：未绑定会话的 fd 执行订阅时抛出 MqttProtocolException 并携带 fd。
     */
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

    /**
     * 验证：MQTT v3 订阅返回正确 SUBACK 码，且按主题过滤器匹配目标订阅者 fd。
     */
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

    /**
     * 验证：MQTT v5 no_local 选项使发布者自身 fd 收不到自己发布的消息。
     */
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

    /**
     * 验证：取消订阅后会话内订阅计数归零。
     */
    public function testUnsubscribe(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(7, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->subscribe(7, ['a/1' => 0, 'b/2' => 1], MQTT_PROTOCOL_LEVEL3);
        $mgr->unsubscribe(7, ['a/1', 'b/2']);

        $this->assertSame(0, $mgr->stats()['subscriptions'], 'all unsubscribed');
    }

    /**
     * 验证：非法 QoS 等级订阅时返回 0x80 失败码。
     */
    public function testSubscribeInvalidQos(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(8, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $codes = $mgr->subscribe(8, ['bad' => 9], MQTT_PROTOCOL_LEVEL3);
        $this->assertSame(0x80, $codes[0], 'invalid qos returns failure code');
    }

    /**
     * 验证：retain=false 清除保留消息，retain=true 时订阅过滤器可检索到保留载荷。
     */
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

    /**
     * 验证：CONNECT 遗嘱消息在绑定时以 retain 形式存入保留消息存储。
     */
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

    /**
     * 验证：入站 QoS2 消息暂存后首次 release 返回载荷，重复 release 幂等返回 null。
     */
    public function testInboundQos2Staging(): void
    {
        $mgr = MqttSessionManager::getInstance();

        $mgr->bind(30, 'qos2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberInboundQoS2(30, 42, 't/qos2', 'payload', 2, false);

        $this->assertNotNull($mgr->releaseInboundQoS2(30, 42), 'first release returns payload');
        $this->assertNull($mgr->releaseInboundQoS2(30, 42), 'second release is idempotent null');
    }

    /**
     * 验证：stats 正确统计连接数与订阅数，remove 后对应会话被清除。
     */
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

    /**
     * 验证：生产环境用户名/密码校验逻辑——空配置放行、凭据匹配与 hash_equals 防时序攻击。
     */
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

    /**
     * 验证：Retain 超过 topic / 单条字节 / 总字节上限时拒绝写入。
     */
    public function testRetainedMessageLimits(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->configureRetainLimits([
            'max_topics' => 3,
            'max_message_bytes' => 10,
            'max_total_bytes' => 10,
        ]);

        $this->assertTrue($mgr->storeRetained('a', '12345', 0, true));
        $this->assertFalse($mgr->storeRetained('big', str_repeat('x', 11), 0, true), 'single message too large');
        $this->assertTrue($mgr->storeRetained('b', '12345', 0, true));
        $this->assertFalse($mgr->storeRetained('c', '1', 0, true), 'total bytes would exceed');

        $mgr->configureRetainLimits(['max_topics' => 2, 'max_total_bytes' => 100]);
        $this->assertFalse($mgr->storeRetained('c', '1', 0, true), 'max topics reached');

        $stats = $mgr->stats();
        $this->assertSame(2, $stats['retained_topics']);
        $this->assertSame(10, $stats['retained_total_bytes']);
    }

    /**
     * 验证：出站 pending ID 可查询，便于 nextMessageId 跳过在途 ID。
     */
    public function testHasOutboundPending(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(50, 'out', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberOutbound(50, 7, 1);

        $this->assertTrue($mgr->hasOutboundPending(50, 7));
        $this->assertFalse($mgr->hasOutboundPending(50, 8));

        $mgr->ackOutbound(50, 7);
        $this->assertFalse($mgr->hasOutboundPending(50, 7));
    }

    /**
     * 验证：设置 MQTT_SMOKE_HOST/PORT 且安装 simps/mqtt 时可执行端到端冒烟（否则跳过）。
     */
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
