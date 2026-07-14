<?php

declare(strict_types=1);

/**
 * MQTT 优雅停机单测。
 *
 * Run: php src/Mqtt/Tests/MqttGracefulShutdownTest.php
 */

use Swoolefy\Mqtt\MqttShutdownCoordinator;
use Swoolefy\Mqtt\MqttSessionManager;

require __DIR__ . '/Support/MqttTestBootstrap.php';

function mqttGsAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mqttGsPass(string $name): void
{
    echo "[PASS] {$name}\n";
}

function teardownMqttGraceful(): void
{
    MqttShutdownCoordinator::resetForTest();
    MqttSessionManager::reset();
}

/** 内存标志下停机应拒绝新会话 / 新业务 */
function testShouldRejectWhenShuttingDown(): void
{
    MqttShutdownCoordinator::useMemoryFlagForTest();
    mqttGsAssertTrue(!MqttShutdownCoordinator::shouldRejectNewSessions(), 'not reject before flag');
    mqttGsAssertTrue(!MqttShutdownCoordinator::shouldRejectNewWork(), 'not reject work before flag');

    MqttShutdownCoordinator::setShuttingDownForTest(true);
    mqttGsAssertTrue(MqttShutdownCoordinator::shouldRejectNewSessions(), 'reject new sessions');
    mqttGsAssertTrue(MqttShutdownCoordinator::shouldRejectNewWork(), 'reject new work');

    teardownMqttGraceful();
    mqttGsPass('should reject sessions and work');
}

/** onBeforeShutdown 幂等标记 */
function testOnBeforeShutdownMarks(): void
{
    MqttShutdownCoordinator::useMemoryFlagForTest();
    // isEnabled=false 时 onBeforeShutdown 直接 return；单测走 markShuttingDown
    MqttShutdownCoordinator::markShuttingDown();
    mqttGsAssertTrue(MqttShutdownCoordinator::isShuttingDown(), 'mark works with memory flag');

    teardownMqttGraceful();
    mqttGsPass('mark shutting down');
}

/** 出站 pending 计入 pendingWorkCount，ack 后清零 */
function testOutboundPendingDrainCount(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();
    $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);

    mqttGsAssertTrue($mgr->pendingWorkCount() === 0, 'no pending initially');
    $mgr->rememberOutbound(1, 10, 1);
    $mgr->rememberOutbound(1, 11, 2);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 2, 'two outbound pending');

    $mgr->ackOutbound(1, 10);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 1, 'one left');
    $mgr->ackOutbound(1, 11);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 0, 'cleared');

    MqttSessionManager::reset();
    mqttGsPass('outbound pending drain count');
}

/** 入站 QoS2 暂存计入 pending */
function testInboundQos2CountsAsPending(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();
    $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->rememberInboundQoS2(1, 7, 't/a', 'payload', 2, false);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 1, 'inbound qos2 pending');
    $mgr->releaseInboundQoS2(1, 7);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 0, 'released');

    MqttSessionManager::reset();
    mqttGsPass('inbound qos2 pending count');
}

/** remove 后 pending 一并清理 */
function testRemoveClearsOutboundPending(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();
    $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);
    $mgr->rememberOutbound(1, 3, 1);
    $mgr->remove(1);
    mqttGsAssertTrue($mgr->pendingWorkCount() === 0, 'remove clears outbound');
    mqttGsAssertTrue($mgr->get(1) === null, 'session gone');

    MqttSessionManager::reset();
    mqttGsPass('remove clears outbound');
}

/** recommendedStopTimeout 覆盖 drain + max_wait */
function testRecommendedStopTimeouts(): void
{
    // 无 conf 时 drainTimeout 默认 30
    $force = MqttShutdownCoordinator::recommendedForceKillTimeout(10);
    $total = MqttShutdownCoordinator::recommendedStopTimeout(10);
    mqttGsAssertTrue($force >= 30 + 5, 'force >= drain + half max_wait');
    mqttGsAssertTrue($total >= 30 + 10 + 5, 'total >= drain + max_wait + buffer');
    mqttGsAssertTrue($total > $force, 'total > force');

    mqttGsPass('recommended stop timeouts');
}

/** 同 client_id 重连踢旧连接语义保持 */
function testReconnectKickSemantics(): void
{
    MqttSessionManager::reset();
    $mgr = MqttSessionManager::getInstance();
    $mgr->bind(1, 'device-x', '', 60, MQTT_PROTOCOL_LEVEL3, false);
    $mgr->rememberOutbound(1, 1, 1);
    $mgr->bind(2, 'device-x', '', 60, MQTT_PROTOCOL_LEVEL3, false);

    mqttGsAssertTrue($mgr->get(1) === null, 'old fd kicked');
    mqttGsAssertTrue($mgr->get(2) !== null, 'new fd bound');
    // 旧 fd pending 随 remove 清除
    mqttGsAssertTrue($mgr->pendingWorkCount() === 0, 'old pending cleared on kick');

    MqttSessionManager::reset();
    mqttGsPass('reconnect kick semantics');
}

testShouldRejectWhenShuttingDown();
testOnBeforeShutdownMarks();
testOutboundPendingDrainCount();
testInboundQos2CountsAsPending();
testRemoveClearsOutboundPending();
testRecommendedStopTimeouts();
testReconnectKickSemantics();

echo "All MQTT graceful shutdown tests passed.\n";
