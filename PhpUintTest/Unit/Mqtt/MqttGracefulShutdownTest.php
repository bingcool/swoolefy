<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Mqtt;

use Swoolefy\Mqtt\MqttSessionManager;
use Swoolefy\Mqtt\MqttShutdownCoordinator;
use PhpUintTest\TestCase;

/**
 * MQTT 优雅停机单测。
 */
final class MqttGracefulShutdownTest extends TestCase
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
        MqttShutdownCoordinator::resetForTest();
        MqttSessionManager::reset();
        parent::tearDown();
    }

    public function testShouldRejectWhenShuttingDown(): void
    {
        MqttShutdownCoordinator::useMemoryFlagForTest();
        $this->assertFalse(MqttShutdownCoordinator::shouldRejectNewSessions(), 'not reject before flag');
        $this->assertFalse(MqttShutdownCoordinator::shouldRejectNewWork(), 'not reject work before flag');

        MqttShutdownCoordinator::setShuttingDownForTest(true);
        $this->assertTrue(MqttShutdownCoordinator::shouldRejectNewSessions(), 'reject new sessions');
        $this->assertTrue(MqttShutdownCoordinator::shouldRejectNewWork(), 'reject new work');
    }

    public function testOnBeforeShutdownMarks(): void
    {
        MqttShutdownCoordinator::useMemoryFlagForTest();
        MqttShutdownCoordinator::markShuttingDown();
        $this->assertTrue(MqttShutdownCoordinator::isShuttingDown(), 'mark works with memory flag');
    }

    public function testOutboundPendingDrainCount(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);

        $this->assertSame(0, $mgr->pendingWorkCount(), 'no pending initially');
        $mgr->rememberOutbound(1, 10, 1);
        $mgr->rememberOutbound(1, 11, 2);
        $this->assertSame(2, $mgr->pendingWorkCount(), 'two outbound pending');

        $mgr->ackOutbound(1, 10);
        $this->assertSame(1, $mgr->pendingWorkCount(), 'one left');
        $mgr->ackOutbound(1, 11);
        $this->assertSame(0, $mgr->pendingWorkCount(), 'cleared');
    }

    public function testInboundQos2CountsAsPending(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberInboundQoS2(1, 7, 't/a', 'payload', 2, false);
        $this->assertSame(1, $mgr->pendingWorkCount(), 'inbound qos2 pending');
        $mgr->releaseInboundQoS2(1, 7);
        $this->assertSame(0, $mgr->pendingWorkCount(), 'released');
    }

    public function testRemoveClearsOutboundPending(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(1, 'c1', '', 60, MQTT_PROTOCOL_LEVEL3, true);
        $mgr->rememberOutbound(1, 3, 1);
        $mgr->remove(1);
        $this->assertSame(0, $mgr->pendingWorkCount(), 'remove clears outbound');
        $this->assertNull($mgr->get(1), 'session gone');
    }

    public function testRecommendedStopTimeouts(): void
    {
        $force = MqttShutdownCoordinator::recommendedForceKillTimeout(10);
        $total = MqttShutdownCoordinator::recommendedStopTimeout(10);
        $this->assertGreaterThanOrEqual(30 + 5, $force, 'force >= drain + half max_wait');
        $this->assertGreaterThanOrEqual(30 + 10 + 5, $total, 'total >= drain + max_wait + buffer');
        $this->assertGreaterThan($force, $total, 'total > force');
    }

    public function testReconnectKickSemantics(): void
    {
        $mgr = MqttSessionManager::getInstance();
        $mgr->bind(1, 'device-x', '', 60, MQTT_PROTOCOL_LEVEL3, false);
        $mgr->rememberOutbound(1, 1, 1);
        $mgr->bind(2, 'device-x', '', 60, MQTT_PROTOCOL_LEVEL3, false);

        $this->assertNull($mgr->get(1), 'old fd kicked');
        $this->assertNotNull($mgr->get(2), 'new fd bound');
        $this->assertSame(0, $mgr->pendingWorkCount(), 'old pending cleared on kick');
    }
}
