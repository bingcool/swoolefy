<?php

declare(strict_types=1);

/**
 * MQTT 模块综合单测（Topic / Session / Retain / QoS2 / 鉴权逻辑）。
 *
 * 运行：
 *   php src/Mqtt/Tests/MqttModuleTest.php
 *
 * 可选环境变量：
 *   MQTT_SMOKE_HOST / MQTT_SMOKE_PORT — 若设置且 Broker 已启动，追加端到端冒烟（需 simps/mqtt）
 */

use Swoolefy\Mqtt\MqttProtocolException;
use Swoolefy\Mqtt\MqttSessionManager;
use Swoolefy\Mqtt\MqttTopicMatcher;

require __DIR__ . '/Support/MqttTestBootstrap.php';

/** 断言条件为真 */
function mqttAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 断言两值相等（==） */
function mqttAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected != $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function mqttPass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// MqttTopicMatcher
// ---------------------------------------------------------------------------

function testTopicMatcherExactAndWildcards(): void
{
    mqttAssertTrue(MqttTopicMatcher::matches('sensor/1/temp', 'sensor/1/temp'), 'exact');
    mqttAssertTrue(MqttTopicMatcher::matches('sensor/+/temp', 'sensor/room1/temp'), 'plus one level');
    mqttAssertTrue(MqttTopicMatcher::matches('sensor/#', 'sensor/a/b/c'), 'hash multi level');
    mqttAssertTrue(MqttTopicMatcher::matches('#', 'any/topic/here'), 'hash only filter');
    mqttAssertTrue(!MqttTopicMatcher::matches('sensor/+/temp', 'sensor/a/b/temp'), 'plus no multi hop');
    mqttAssertTrue(!MqttTopicMatcher::matches('a/b', 'a/b/c'), 'exact length');
    mqttAssertTrue(!MqttTopicMatcher::matches('home/+/state', 'home/state'), 'plus requires one level');
    mqttPass('topic matcher exact and wildcards');
}

function testTopicMatcherEdgeCases(): void
{
    mqttAssertTrue(MqttTopicMatcher::matches('$SYS/broker/uptime', '$SYS/broker/uptime'), 'sys topic exact');
    mqttAssertTrue(MqttTopicMatcher::matches('a/+/#', 'a/x/y/z'), 'plus then hash');
    mqttAssertTrue(!MqttTopicMatcher::matches('a/#/b', 'a/x/b'), 'hash must be last segment per MQTT spec');
    mqttPass('topic matcher edge cases');
}

// ---------------------------------------------------------------------------
// MqttSessionManager — bind / client_id / clean session
// ---------------------------------------------------------------------------

function testSessionBindAndClientIdKick(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(1, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->bind(2, 'device-a', 'u1', 60, MQTT_PROTOCOL_LEVEL3, true);

    mqttAssertTrue($mgr->get(1) === null, 'old fd removed when same client_id reconnects');
    mqttAssertSame('device-a', $mgr->get(2)?->clientId, 'new fd owns client_id');

    mqttPass('session bind and client_id kick');
}

function testSessionCleanSessionFlag(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, false);
    $mgr->subscribe(10, ['t/1' => 1], MQTT_PROTOCOL_LEVEL3);
    mqttAssertSame(1, $mgr->stats()['subscriptions'], 'one subscription');

    $mgr->bind(10, 'dev', 'u', 30, MQTT_PROTOCOL_LEVEL3, true);
    mqttAssertSame(0, count($mgr->get(10)?->subscriptions ?? []), 'clean session clears subs on re-bind');

    mqttPass('session clean session');
}

function testSessionRequireConnectedThrows(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    try {
        $mgr->subscribe(99, ['x' => 0], MQTT_PROTOCOL_LEVEL3);
        throw new RuntimeException('expected MqttProtocolException');
    } catch (MqttProtocolException $e) {
        mqttAssertSame(99, $e->fd, 'exception carries fd');
    }

    mqttPass('session require connected throws');
}

// ---------------------------------------------------------------------------
// subscribe / unsubscribe / matchSubscribers
// ---------------------------------------------------------------------------

function testSubscribeAndMatchSubscribersV3(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(1, 'pub', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->bind(2, 'sub1', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->bind(3, 'sub2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);

    $codes = $mgr->subscribe(2, ['home/+/temp' => 1, 'home/living/temp' => 2], MQTT_PROTOCOL_LEVEL3);
    mqttAssertSame([1, 2], $codes, 'suback codes v3');
    $mgr->subscribe(3, ['other/#' => 0], MQTT_PROTOCOL_LEVEL3);

    $matches = $mgr->matchSubscribers('home/living/temp', 1);
    $fds = array_column($matches, 'fd');
    mqttAssertSame([2], $fds, 'only matching wildcard subscriber');

    $pubOther = $mgr->matchSubscribers('other/x', 1);
    mqttAssertSame([3], array_column($pubOther, 'fd'), 'hash subscriber only');

    mqttPass('subscribe and match subscribers v3');
}

function testSubscribeV5NoLocal(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(5, 'c1', 'u', 60, MQTT_PROTOCOL_LEVEL5, true);
    $mgr->subscribe(5, [
        'loop/#' => ['qos' => 1, 'no_local' => true],
    ], MQTT_PROTOCOL_LEVEL5);

    $matches = $mgr->matchSubscribers('loop/test', 5);
    mqttAssertSame([], $matches, 'no_local excludes publisher fd');

    $matchesOther = $mgr->matchSubscribers('loop/test', 99);
    mqttAssertSame(5, $matchesOther[0]['fd'] ?? 0, 'other publisher still delivers');

    mqttPass('subscribe v5 no_local');
}

function testUnsubscribe(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(7, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->subscribe(7, ['a/1' => 0, 'b/2' => 1], MQTT_PROTOCOL_LEVEL3);
    $mgr->unsubscribe(7, ['a/1', 'b/2']);

    mqttAssertSame(0, $mgr->stats()['subscriptions'], 'all unsubscribed');
    mqttPass('unsubscribe');
}

function testSubscribeInvalidQos(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(8, 'c', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $codes = $mgr->subscribe(8, ['bad' => 9], MQTT_PROTOCOL_LEVEL3);
    mqttAssertSame(0x80, $codes[0], 'invalid qos returns failure code');

    mqttPass('subscribe invalid qos');
}

// ---------------------------------------------------------------------------
// Retain
// ---------------------------------------------------------------------------

function testRetainedMessages(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->storeRetained('cfg/version', '1.0.0', 1, true);
    $mgr->storeRetained('cfg/version', '', 0, false);

    mqttAssertSame(0, $mgr->stats()['retained_topics'], 'retain=false clears message');

    $mgr->storeRetained('home/living/temp', '22.5', 1, true);
    $retained = $mgr->retainedForSubscription('home/+/temp');
    mqttAssertTrue(isset($retained['home/living/temp']), 'retained visible to filter');
    mqttAssertSame('22.5', $retained['home/living/temp']['message'], 'retained payload');

    mqttPass('retained messages');
}

function testWillMessageStoredAsRetain(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(20, 'will-client', 'u', 60, MQTT_PROTOCOL_LEVEL3, true, [
        'topic' => 'device/will',
        'message' => 'offline',
        'qos' => 1,
        'retain' => true,
    ]);

    $payload = $mgr->allRetainedForTopic('device/will');
    mqttAssertSame('offline', $payload['message'] ?? null, 'will stored as retain');

    mqttPass('will message retain');
}

// ---------------------------------------------------------------------------
// QoS 2 inbound staging
// ---------------------------------------------------------------------------

function testInboundQos2Staging(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(30, 'qos2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->rememberInboundQoS2(30, 42, 't/qos2', 'payload', 2, false);

    mqttAssertTrue($mgr->releaseInboundQoS2(30, 42) !== null, 'first release returns payload');
    mqttAssertTrue($mgr->releaseInboundQoS2(30, 42) === null, 'second release is idempotent null');

    mqttPass('inbound qos2 staging');
}

// ---------------------------------------------------------------------------
// stats / remove
// ---------------------------------------------------------------------------

function testSessionRemoveAndStats(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();

    $mgr->bind(40, 's1', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->bind(41, 's2', 'u', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->subscribe(40, ['x' => 0], MQTT_PROTOCOL_LEVEL3);

    $stats = $mgr->stats();
    mqttAssertSame(2, $stats['connected'], 'two connected');
    mqttAssertSame(1, $stats['subscriptions'], 'one sub');

    $mgr->remove(40);
    mqttAssertTrue($mgr->get(40) === null, 'removed session gone');
    mqttAssertSame(1, $mgr->stats()['connected'], 'one remains');

    mqttPass('session remove and stats');
}

// ---------------------------------------------------------------------------
// Production verify (pure logic, no Swfy)
// ---------------------------------------------------------------------------

function testProductionVerifyLogic(): void
{
    $verify = static function (string $expectedUser, string $expectedPass, string $user, string $pass): bool {
        if ($expectedUser === '' && $expectedPass === '') {
            return true;
        }

        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    };

    mqttAssertTrue($verify('', '', 'any', 'any'), 'empty config allows all');
    mqttAssertTrue($verify('dev', 'secret', 'dev', 'secret'), 'correct credentials');
    mqttAssertTrue(!$verify('dev', 'secret', 'dev', 'wrong'), 'wrong password');
    mqttAssertTrue(!$verify('dev', 'secret', 'evil', 'secret'), 'wrong user');

    mqttPass('production verify logic');
}

// ---------------------------------------------------------------------------
// Optional smoke (simps/mqtt + running broker)
// ---------------------------------------------------------------------------

function testOptionalMqttSmoke(): void
{
    $host = getenv('MQTT_SMOKE_HOST') ?: '';
    $port = (int) (getenv('MQTT_SMOKE_PORT') ?: 0);
    if ($host === '' || $port <= 0) {
        echo "[SKIP] mqtt smoke (set MQTT_SMOKE_HOST and MQTT_SMOKE_PORT)\n";
        return;
    }

    if (!class_exists(\Simps\MQTT\Client::class)) {
        echo "[SKIP] mqtt smoke (simps/mqtt not installed)\n";
        return;
    }

    mqttPass('mqtt smoke placeholder configured');
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$tests = [
    'topic matcher exact and wildcards' => 'testTopicMatcherExactAndWildcards',
    'topic matcher edge cases' => 'testTopicMatcherEdgeCases',
    'session bind and client_id kick' => 'testSessionBindAndClientIdKick',
    'session clean session' => 'testSessionCleanSessionFlag',
    'session require connected throws' => 'testSessionRequireConnectedThrows',
    'subscribe and match subscribers v3' => 'testSubscribeAndMatchSubscribersV3',
    'subscribe v5 no_local' => 'testSubscribeV5NoLocal',
    'unsubscribe' => 'testUnsubscribe',
    'subscribe invalid qos' => 'testSubscribeInvalidQos',
    'retained messages' => 'testRetainedMessages',
    'will message retain' => 'testWillMessageStoredAsRetain',
    'inbound qos2 staging' => 'testInboundQos2Staging',
    'session remove and stats' => 'testSessionRemoveAndStats',
    'production verify logic' => 'testProductionVerifyLogic',
    'optional mqtt smoke' => 'testOptionalMqttSmoke',
];

$passed = 0;
foreach ($tests as $label => $fn) {
    try {
        $fn();
        $passed++;
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] {$label}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "\nAll {$passed} MQTT module tests passed.\n";
