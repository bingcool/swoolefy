<?php
/**
 * 推送 enricher 单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketPushEnricherTest.php
 */

use Swoolefy\Websocket\Push\CallablePushPayloadEnricher;
use Swoolefy\Websocket\Push\PushPayloadEnricherFactory;
use Swoolefy\Websocket\Push\PushPayloadResolver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 注册 mock enricher：m1 展开、缺失返回 null、其余原样透传 */
function bootMockEnricher(): void
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

/** 引用模式：含 msg_id 时 enricher 查库展开为完整 message */
function testEnricherExpandsMsgId(): void
{
    bootMockEnricher();

    $resolved = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'm1'], 1);
    assertTrue(is_array($resolved), 'resolved should be array');
    assertTrue(($resolved['message']['msg'] ?? '') === 'loaded-from-db', 'enricher should load message body');

    PushPayloadEnricherFactory::reset();
    echo "[OK] enricher expands msg_id\n";
}

/** 内联完整载荷：无 msg_id 时 enricher 原样透传 */
function testEnricherPassthroughInlinePayload(): void
{
    bootMockEnricher();

    $passthrough = PushPayloadResolver::resolve('chat.private', [
        'message' => ['msg' => 'inline'],
    ], 1);
    assertTrue(($passthrough['message']['msg'] ?? '') === 'inline', 'inline payload should pass through');

    PushPayloadEnricherFactory::reset();
    echo "[OK] enricher passthrough inline\n";
}

/** enricher 返回 null 时 resolve 返回 null，投递层应跳过该 fd */
function testEnricherSkipWhenNull(): void
{
    bootMockEnricher();

    $skipped = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'missing'], 1);
    assertTrue($skipped === null, 'missing message should skip delivery');

    PushPayloadEnricherFactory::reset();
    echo "[OK] enricher skip when null\n";
}

testEnricherExpandsMsgId();
testEnricherPassthroughInlinePayload();
testEnricherSkipWhenNull();

echo "All websocket push enricher tests passed.\n";
