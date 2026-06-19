<?php
/**
 * 推送 enricher 单元测试。
 *
 * 覆盖场景：
 * 1. 含 msg_id → enricher 展开为完整 message
 * 2. 内联载荷 → enricher 原样透传
 * 3. enricher 返回 null → 跳过投递
 *
 * Run: php src/Websocket/Tests/WebsocketPushEnricherTest.php
 */

use Swoolefy\Websocket\Push\CallablePushPayloadEnricher;
use Swoolefy\Websocket\Push\PushPayloadEnricherFactory;
use Swoolefy\Websocket\Push\PushPayloadResolver;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

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

// 引用模式：enricher 展开 msg_id
$resolved = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'm1'], 1);
assertTrue(is_array($resolved), 'resolved should be array');
assertTrue(($resolved['message']['msg'] ?? '') === 'loaded-from-db', 'enricher should load message body');

// 内联载荷：enricher 原样透传
$passthrough = PushPayloadResolver::resolve('chat.private', [
    'message' => ['msg' => 'inline'],
], 1);
assertTrue(($passthrough['message']['msg'] ?? '') === 'inline', 'inline payload should pass through');

// enricher 返回 null → 跳过投递
$skipped = PushPayloadResolver::resolve('chat.private', ['msg_id' => 'missing'], 1);
assertTrue($skipped === null, 'missing message should skip delivery');

PushPayloadEnricherFactory::reset();

echo "All websocket push enricher tests passed.\n";
