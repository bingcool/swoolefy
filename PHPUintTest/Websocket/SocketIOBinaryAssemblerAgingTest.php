<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use PHPUintTest\TestCase;
use ReflectionClass;
use Swoolefy\Websocket\SocketIO\SocketIOBinaryAssembler;
use Swoolefy\Websocket\SocketIO\SocketIOBinaryData;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;

/**
 * 阶段五 7.5（审计项 44）：Socket.IO 二进制附件 pending 老化与限额。
 * 覆盖完整组装、空闲超时释放、总生命周期不可被小帧无限续期。
 */
final class SocketIOBinaryAssemblerAgingTest extends TestCase
{
    protected function tearDown(): void
    {
        SocketIOBinaryAssembler::resetForTest();
        parent::tearDown();
    }

    /**
     * 测完整附件：feedText + feedBinary 能组装为 SOCKET_EVENT。
     * 对应问题：老化逻辑不得破坏正常收齐路径。
     */
    public function testCompleteAttachmentsAssemble(): void
    {
        $packet = SocketIOPacket::parse(
            '45-1-["file.upload",{"filename":"a.bin","content":{"_placeholder":true,"num":0}}]'
        );
        $this->assertNull(SocketIOBinaryAssembler::feedText(1, $packet));
        $this->assertSame(1, SocketIOBinaryAssembler::pendingCount());

        $ready = SocketIOBinaryAssembler::feedBinary(
            1,
            SocketIOBinaryData::encodeAttachmentFrame('payload-bytes')
        );
        $this->assertNotNull($ready);
        $this->assertSame(SocketIOPacket::SOCKET_EVENT, $ready->socketType);
        $this->assertSame('payload-bytes', $ready->data['content'] ?? '');
        $this->assertSame(0, SocketIOBinaryAssembler::pendingCount());
    }

    /**
     * 测缺失附件空闲超时：cleanupStale 释放 pending。
     * 对应问题：客户端只发文本帧不发附件会永久占内存。
     */
    public function testMissingAttachmentsExpireByIdleTimeout(): void
    {
        $packet = SocketIOPacket::parse(
            '45-1-["file.upload",{"content":{"_placeholder":true,"num":0}}]'
        );
        SocketIOBinaryAssembler::feedText(2, $packet);
        $this->backdatePending(2, updatedAt: time() - 120, createdAt: time() - 120);

        $removed = SocketIOBinaryAssembler::cleanupStale(time(), 60, 300);
        $this->assertSame(1, $removed);
        $this->assertSame(0, SocketIOBinaryAssembler::pendingCount());
    }

    /**
     * 测持续小帧刷新 updated_at 仍不能越过总生命周期。
     * 对应问题：攻击者用 drip 附件无限续期 pending。
     */
    public function testDripFeedsCannotExtendPastMaxLifetime(): void
    {
        $packet = SocketIOPacket::parse(
            '45-2-["file.upload",[{"_placeholder":true,"num":0},{"_placeholder":true,"num":1}]]'
        );
        SocketIOBinaryAssembler::feedText(3, $packet);

        // 模拟已创建很久，但刚刚又收到一小帧（updated_at 很新）
        $this->backdatePending(3, updatedAt: time(), createdAt: time() - 400);
        SocketIOBinaryAssembler::feedBinary(
            3,
            SocketIOBinaryData::encodeAttachmentFrame('tiny')
        );
        $this->assertSame(1, SocketIOBinaryAssembler::pendingCount());

        $removed = SocketIOBinaryAssembler::cleanupStale(time(), 60, 300);
        $this->assertSame(1, $removed);
        $this->assertSame(0, SocketIOBinaryAssembler::pendingCount());
    }

    private function backdatePending(int $fd, int $updatedAt, int $createdAt): void
    {
        $ref = new ReflectionClass(SocketIOBinaryAssembler::class);
        $prop = $ref->getProperty('pending');
        $prop->setAccessible(true);
        /** @var array<int, array<string, mixed>> $all */
        $all = $prop->getValue();
        $this->assertArrayHasKey($fd, $all);
        $all[$fd]['updated_at'] = $updatedAt;
        $all[$fd]['created_at'] = $createdAt;
        $prop->setValue(null, $all);
    }
}
