<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use Swoolefy\Websocket\SocketIO\SocketIOBinaryAssembler;
use Swoolefy\Websocket\SocketIO\SocketIOBinaryData;
use Swoolefy\Websocket\SocketIO\SocketIONamespaceRegistry;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;

/**
 * Socket.IO 多 namespace + 二进制附件单元测试。
 */
final class WebsocketSocketIOAdvancedTest extends TestCase
{
    protected function tearDown(): void
    {
        SocketIOBinaryAssembler::resetForTest();
        SocketIONamespaceRegistry::resetForTest();
        parent::tearDown();
    }

    public function testParseBinaryEventPacket(): void
    {
        $raw = '45-1-["file.upload",{"_placeholder":true,"num":0}]';
        $packet = SocketIOPacket::parse($raw);
        $this->assertSame(SocketIOPacket::SOCKET_BINARY_EVENT, $packet->socketType);
        $this->assertSame(1, $packet->attachmentCount);
        $this->assertSame('file.upload', $packet->event);
    }

    public function testBinaryPrepareAndInject(): void
    {
        $bytes = "binary\xff\xfe";
        [$prepared, $attachments] = SocketIOBinaryData::prepareArgs([
            'file.upload',
            ['filename' => 'a.bin', 'content' => SocketIOBinaryData::wrap($bytes)],
        ]);
        $this->assertCount(1, $attachments);
        $this->assertSame($bytes, $attachments[0]);

        $merged = SocketIOBinaryData::injectAttachments($prepared, $attachments);
        $this->assertSame($bytes, $merged[1]['content']);
    }

    public function testBinaryAssemblerWebSocket(): void
    {
        SocketIOBinaryAssembler::resetForTest();
        $packet = SocketIOPacket::parse(
            '45-1-["file.upload",{"filename":"a.bin","content":{"_placeholder":true,"num":0}}]'
        );
        $pending = SocketIOBinaryAssembler::feedText(1, $packet);
        $this->assertNull($pending);

        $ready = SocketIOBinaryAssembler::feedBinary(1, SocketIOBinaryData::encodeAttachmentFrame('payload-bytes'));
        $this->assertNotNull($ready);
        $this->assertSame(SocketIOPacket::SOCKET_EVENT, $ready->socketType);
        $this->assertSame('payload-bytes', $ready->data['content'] ?? '');
    }

    public function testEncodeEventFramesWithBinary(): void
    {
        $frames = SocketIOPacket::encodeEventFrames('file.message', [[
            'filename' => 'x.bin',
            'content' => SocketIOBinaryData::wrap("\x00\x01"),
        ]], '/chat');

        $this->assertCount(2, $frames);
        $this->assertSame(WEBSOCKET_OPCODE_TEXT, $frames[0][1]);
        $this->assertSame(WEBSOCKET_OPCODE_BINARY, $frames[1][1]);
        $this->assertStringContainsString('/chat,', $frames[0][0]);
        $this->assertStringContainsString('-1-', $frames[0][0]);
    }

    public function testNamespaceEndpointResolve(): void
    {
        SocketIONamespaceRegistry::setConfigOverride([
            'allowed_namespaces' => ['*'],
            'event_routes' => ['chat.send' => 'Service/Chat/Send'],
            'namespaces' => [
                '/admin' => ['event_routes' => ['admin.notify' => 'Service/Admin/Notify']],
            ],
        ]);

        $this->assertSame(
            'Service/Admin/Notify',
            SocketIONamespaceRegistry::resolveEndpoint('admin.notify', '/admin', ['socketio' => []])
        );
        $this->assertSame(
            'Service/Chat/Send',
            SocketIONamespaceRegistry::resolveEndpoint('chat.send', '/admin', ['socketio' => []])
        );
        $this->assertSame(
            'ops/custom/event',
            SocketIONamespaceRegistry::resolveEndpoint('custom.event', '/ops', ['socketio' => []])
        );
    }

    public function testNamespaceAllowedList(): void
    {
        $config = ['socketio' => [
            'allowed_namespaces' => ['/', '/chat'],
        ]];
        $this->assertTrue(SocketIONamespaceRegistry::isAllowed('/', $config));
        $this->assertTrue(SocketIONamespaceRegistry::isAllowed('/chat', $config));
        $this->assertFalse(SocketIONamespaceRegistry::isAllowed('/admin', $config));
    }

    public function testResolvePushNamespace(): void
    {
        $this->assertSame(
            '/notify',
            SocketIONamespaceRegistry::resolvePushNamespace(['namespace' => '/notify'])
        );
        $this->assertSame(
            '/chat',
            SocketIONamespaceRegistry::resolvePushNamespace(['_socketio' => ['namespace' => '/chat']])
        );
    }
}
