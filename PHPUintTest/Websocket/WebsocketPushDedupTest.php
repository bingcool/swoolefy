<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use PHPUintTest\Websocket\Support\WebsocketAppConstants;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDedupStore;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;

/**
 * 推送去重（PushDedupStore / PushDeliveryResult）单元测试。
 */
final class WebsocketPushDedupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        WebsocketAppConstants::ensure();
    }

    protected function tearDown(): void
    {
        $this->teardownDedupConfig();
        parent::tearDown();
    }

    private function bootDedupConfig(): void
    {
        ClusterConfig::setWebsocketOverride([
            'cluster' => [
                'enable' => true,
                'server_id' => 'ws-dedup-test',
                'redis' => ['key_prefix' => 'ws:test:'],
                'push' => [
                    'dedup' => ['enable' => true, 'ttl' => 3600],
                ],
            ],
        ]);
        PushDedupStore::useMemoryStoreForTest();
    }

    private function teardownDedupConfig(): void
    {
        ClusterConfig::setWebsocketOverride(null);
        PushDedupStore::resetForTest();
    }

    public function testMarkAndDetectDuplicate(): void
    {
        $this->bootDedupConfig();

        $msgId = 'dedup-msg-001';
        $this->assertFalse(PushDedupStore::isDuplicate($msgId));
        PushDedupStore::markProcessed($msgId);
        $this->assertTrue(PushDedupStore::isDuplicate($msgId));
    }

    public function testDuplicateResultShouldAck(): void
    {
        $result = PushDeliveryResult::duplicateSkipped();
        $this->assertTrue($result->duplicateSkipped);
        $this->assertTrue($result->shouldAck());
        $this->assertSame(0, $result->delivered);
    }

    public function testDedupDisabled(): void
    {
        ClusterConfig::setWebsocketOverride([
            'cluster' => [
                'enable' => true,
                'server_id' => 'ws-dedup-off',
                'push' => ['dedup' => ['enable' => false]],
            ],
        ]);
        PushDedupStore::useMemoryStoreForTest();

        PushDedupStore::markProcessed('x');
        $this->assertFalse(PushDedupStore::isDuplicate('x'));
    }

    public function testEmptyMsgIdSkipped(): void
    {
        $this->bootDedupConfig();

        PushDedupStore::markProcessed('');
        $this->assertFalse(PushDedupStore::isDuplicate(''));
    }
}
