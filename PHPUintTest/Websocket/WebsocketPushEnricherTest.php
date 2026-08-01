<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Push\CallablePushPayloadEnricher;
use Swoolefy\Websocket\Push\PushPayloadEnricherFactory;
use Swoolefy\Websocket\Push\PushPayloadEnricherInterface;
use Swoolefy\Websocket\Push\PushPayloadResolver;

/**
 * 推送 enricher 单元测试。
 */
final class WebsocketPushEnricherTest extends TestCase
{
    protected function tearDown(): void
    {
        PushPayloadEnricherFactory::reset();
        ClusterConfig::setWebsocketOverride(null);
        parent::tearDown();
    }

    private function bootMockEnricher(): void
    {
        PushPayloadEnricherFactory::setOverride(new CallablePushPayloadEnricher(
            static function (string $event, array $data, int $fd): ?array {
                if (($data['msg_id'] ?? '') === 'missing') {
                    return null;
                }
                if (($data['msg_id'] ?? '') === 'm1') {
                    return array_merge($data, [
                        'message' => [
                            'type' => 'text',
                            'msg_id' => 'm1',
                            'msg' => 'loaded-from-db',
                            'ts' => 123,
                        ],
                    ]);
                }

                return $data;
            }
        ));
    }

    public function testEnricherExpandsMsgId(): void
    {
        $this->bootMockEnricher();

        $resolved = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'm1'], 1);
        $this->assertIsArray($resolved);
        $this->assertSame('loaded-from-db', $resolved['message']['msg'] ?? '');
    }

    public function testEnricherPassthroughInlinePayload(): void
    {
        $this->bootMockEnricher();

        $passthrough = PushPayloadResolver::resolve('chat.private', [
            'message' => ['msg' => 'inline'],
        ], 1);
        $this->assertSame('inline', $passthrough['message']['msg'] ?? '');
    }

    public function testEnricherSkipWhenNull(): void
    {
        $this->bootMockEnricher();

        $skipped = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'missing'], 1);
        $this->assertNull($skipped);
    }

    /**
     * 类名配置下每次 get() 新建实例，避免请求态成员被多协程复用。
     */
    public function testFactoryCreatesFreshInstancePerGet(): void
    {
        ClusterConfig::setWebsocketOverride([
            'push' => [
                'enricher' => StatefulEnricherForFactoryTest::class,
            ],
        ]);
        PushPayloadEnricherFactory::reset();

        $a = PushPayloadEnricherFactory::get();
        $b = PushPayloadEnricherFactory::get();
        $this->assertInstanceOf(StatefulEnricherForFactoryTest::class, $a);
        $this->assertInstanceOf(StatefulEnricherForFactoryTest::class, $b);
        $this->assertNotSame($a, $b);

        $a->stamp = 'from-a';
        $this->assertNotSame('from-a', $b->stamp);
    }
}

/** @internal 仅单测：带可变成员，用于验证 Factory 不缓存业务实例 */
final class StatefulEnricherForFactoryTest implements PushPayloadEnricherInterface
{
    public string $stamp = '';

    public function enrich(string $event, array $data, int $fd): ?array
    {
        return $data;
    }
}
