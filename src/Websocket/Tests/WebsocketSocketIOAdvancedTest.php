<?php
/**
 * Socket.IO 多 namespace + 二进制附件单元测试。
 *
 * Run: php src/Websocket/Tests/WebsocketSocketIOAdvancedTest.php
 */

use Swoolefy\Websocket\SocketIO\SocketIOBinaryAssembler;
use Swoolefy\Websocket\SocketIO\SocketIOBinaryData;
use Swoolefy\Websocket\SocketIO\SocketIONamespaceRegistry;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 解析带 `-N` 后缀的二进制 event 包 */
function testParseBinaryEventPacket(): void
{
    $raw = '45-1-["file.upload",{"_placeholder":true,"num":0}]';
    $packet = SocketIOPacket::parse($raw);
    assertTrue($packet->socketType === SocketIOPacket::SOCKET_BINARY_EVENT, 'binary event type');
    assertTrue($packet->attachmentCount === 1, 'attachment count');
    assertTrue($packet->event === 'file.upload', 'event name');

    echo "[OK] parse binary event packet\n";
}

/** prepareArgs / injectAttachments 应还原二进制内容 */
function testBinaryPrepareAndInject(): void
{
    $bytes = "binary\xff\xfe";
    [$prepared, $attachments] = SocketIOBinaryData::prepareArgs([
        'file.upload',
        ['filename' => 'a.bin', 'content' => SocketIOBinaryData::wrap($bytes)],
    ]);
    assertTrue(count($attachments) === 1, 'one attachment');
    assertTrue($attachments[0] === $bytes, 'attachment bytes preserved');

    $merged = SocketIOBinaryData::injectAttachments($prepared, $attachments);
    assertTrue($merged[1]['content'] === $bytes, 'binary content injected');

    echo "[OK] binary prepare and inject\n";
}

/** WebSocket 分帧组装：文本 + 二进制帧 */
function testBinaryAssemblerWebSocket(): void
{
    SocketIOBinaryAssembler::resetForTest();
    $packet = SocketIOPacket::parse('45-1-["file.upload",{"filename":"a.bin","content":{"_placeholder":true,"num":0}}]');
    $pending = SocketIOBinaryAssembler::feedText(1, $packet);
    assertTrue($pending === null, 'waiting for binary frame');

    $ready = SocketIOBinaryAssembler::feedBinary(1, SocketIOBinaryData::encodeAttachmentFrame('payload-bytes'));
    assertTrue($ready !== null, 'assembled packet ready');
    assertTrue($ready->socketType === SocketIOPacket::SOCKET_EVENT, 'normalized to event');
    assertTrue(($ready->data['content'] ?? '') === 'payload-bytes', 'payload injected into data');

    SocketIOBinaryAssembler::resetForTest();
    echo "[OK] binary assembler websocket\n";
}

/** encodeEventFrames 含二进制时应返回 TEXT + BINARY 两帧 */
function testEncodeEventFramesWithBinary(): void
{
    $frames = SocketIOPacket::encodeEventFrames('file.message', [[
        'filename' => 'x.bin',
        'content' => SocketIOBinaryData::wrap("\x00\x01"),
    ]], '/chat');

    assertTrue(count($frames) === 2, 'text + binary frame');
    assertTrue($frames[0][1] === WEBSOCKET_OPCODE_TEXT, 'first is text');
    assertTrue($frames[1][1] === WEBSOCKET_OPCODE_BINARY, 'second is binary');
    assertTrue(str_contains($frames[0][0], '/chat,'), 'namespace prefix in text frame');
    assertTrue(str_contains($frames[0][0], '-1-'), 'attachment marker');

    echo "[OK] encode event frames with binary\n";
}

/** namespace 路由：优先 namespaces 配置，其次全局 event_routes */
function testNamespaceEndpointResolve(): void
{
    SocketIONamespaceRegistry::setConfigOverride([
        'allowed_namespaces' => ['*'],
        'event_routes' => ['chat.send' => 'Service/Chat/Send'],
        'namespaces' => [
            '/admin' => ['event_routes' => ['admin.notify' => 'Service/Admin/Notify']],
        ],
    ]);

    assertTrue(
        SocketIONamespaceRegistry::resolveEndpoint('admin.notify', '/admin', ['socketio' => []]) === 'Service/Admin/Notify',
        'namespace route'
    );
    assertTrue(
        SocketIONamespaceRegistry::resolveEndpoint('chat.send', '/admin', ['socketio' => []]) === 'Service/Chat/Send',
        'fallback global route'
    );
    assertTrue(
        SocketIONamespaceRegistry::resolveEndpoint('custom.event', '/ops', ['socketio' => []]) === 'ops/custom/event',
        'convention route for non-default ns'
    );

    SocketIONamespaceRegistry::resetForTest();
    echo "[OK] namespace endpoint resolve\n";
}

/** allowed_namespaces 白名单校验 */
function testNamespaceAllowedList(): void
{
    $config = ['socketio' => [
        'allowed_namespaces' => ['/', '/chat'],
    ]];
    assertTrue(SocketIONamespaceRegistry::isAllowed('/', $config), '/ allowed');
    assertTrue(SocketIONamespaceRegistry::isAllowed('/chat', $config), '/chat allowed');
    assertTrue(!SocketIONamespaceRegistry::isAllowed('/admin', $config), '/admin denied');

    echo "[OK] namespace allowed list\n";
}

/** push 数据中的 namespace 字段解析 */
function testResolvePushNamespace(): void
{
    assertTrue(
        SocketIONamespaceRegistry::resolvePushNamespace(['namespace' => '/notify']) === '/notify',
        'top-level namespace'
    );
    assertTrue(
        SocketIONamespaceRegistry::resolvePushNamespace(['_socketio' => ['namespace' => '/chat']]) === '/chat',
        '_socketio.namespace'
    );

    echo "[OK] resolve push namespace\n";
}

testParseBinaryEventPacket();
testBinaryPrepareAndInject();
testBinaryAssemblerWebSocket();
testEncodeEventFramesWithBinary();
testNamespaceEndpointResolve();
testNamespaceAllowedList();
testResolvePushNamespace();

echo "All websocket socket.io advanced tests passed.\n";
