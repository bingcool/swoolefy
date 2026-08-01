<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Rpc;

use PHPUintTest\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Swoolefy\Rpc\Pack;
use Swoolefy\Rpc\Text;

/**
 * 阶段五 7.1（审计项 11、12）：RPC 安全反序列化与包长校验。
 * 覆盖对象注入防护、非法包长关连、二进制 substr 切片与错误日志不含 payload。
 */
final class PackSecureDecodeTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetPackStatics();
        parent::tearDown();
    }

    /**
     * 测 serialize 模式 allowed_classes=false：恶意对象 payload 不得实例化。
     * 对应问题：无限制 unserialize 可导致对象注入。
     */
    public function testSerializeDecodeDoesNotInstantiateObjects(): void
    {
        $payload = serialize(new \stdClass());
        $decoded = Pack::decode($payload, Pack::DECODE_PHP);

        $this->assertIsObject($decoded);
        $this->assertSame('__PHP_Incomplete_Class', get_class($decoded));
        $this->assertNotInstanceOf(\stdClass::class, $decoded);

        $textDecoded = Text::decode($payload, Text::DECODE_PHP);
        $this->assertSame('__PHP_Incomplete_Class', get_class($textDecoded));
    }

    /**
     * 测包体长度 0 / 超大时拒绝并清理当前 fd 半包，异常信息不含 payload。
     * 对应问题：未校验 length 可能导致超大分配或异常路径泄露包体。
     */
    public function testInvalidBodyLengthRejectsAndDoesNotLogPayload(): void
    {
        $pack = $this->newPackWithMockServer();
        $pack->setHeaderStruct(['length' => 'N']);
        $pack->setHeaderLength(4);
        $pack->setPacketMaxlength(64);
        $this->forceUnpackType('Nlength');

        $closed = [];
        $serverProp = $this->property($pack, 'server');
        $serverProp->setValue($pack, new class($closed) {
            public function __construct(private array &$closed)
            {
            }

            public function exists(int $fd): bool
            {
                return true;
            }

            public function close(int $fd, bool $reset = false): bool
            {
                $this->closed[] = $fd;

                return true;
            }
        });

        $headers = $this->property($pack, '_headers');
        $headers->setValue($pack, [7 => ['length' => 1]]);

        foreach ([0, 128] as $badLength) {
            $packet = pack('N', $badLength) . str_repeat('x', 8);
            try {
                $pack->decodePack(7, $packet);
                $this->fail('expected exception for length=' . $badLength);
            } catch (\Exception $e) {
                $this->assertStringContainsString('Invalid packet body length', $e->getMessage());
                $this->assertStringNotContainsString(str_repeat('x', 8), $e->getMessage());
            }
            $this->assertArrayNotHasKey(7, $headers->getValue($pack));
        }

        $this->assertSame([7, 7], $closed);
    }

    /**
     * 测二进制包体含非法 UTF-8 时 substr 仍能正确切片并 serialize 解码。
     * 对应问题：mb_strcut 按字符截断会破坏二进制 payload。
     */
    public function testBinaryPayloadUsesSubstrNotMbStrcut(): void
    {
        $pack = $this->newPackWithMockServer();
        $pack->setHeaderStruct(['length' => 'N']);
        $pack->setHeaderLength(4);
        $pack->setPacketMaxlength(1024);
        $pack->setSerializeType('serialize');
        $this->forceUnpackType('Nlength');

        // serialize 可承载原始字节；若误用 mb_strcut 会截断/损坏 \xff\xfe
        $body = serialize(['bin' => "\xff\xfe\x00\x01raw"]);
        $packet = pack('N', strlen($body)) . $body;

        [$header, $data] = $pack->decodePack(3, $packet);
        $this->assertSame(strlen($body), (int) $header['length']);
        $this->assertSame("\xff\xfe\x00\x01raw", $data['bin']);
    }

    /**
     * 测负数长度（unpack 成无符号大整数后超限）被拒绝。
     * 对应问题：恶意 length 字段绕过上限。
     */
    public function testNegativePackedLengthIsRejectedAsTooBig(): void
    {
        $pack = $this->newPackWithMockServer();
        $pack->setHeaderStruct(['length' => 'N']);
        $pack->setHeaderLength(4);
        $pack->setPacketMaxlength(1024);
        $this->forceUnpackType('Nlength');

        // 0xFFFFFFFF 作为无符号长度远超 packetMaxlength
        $packet = pack('N', 0xFFFFFFFF) . 'abcd';
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid packet body length');
        $pack->decodePack(1, $packet);
    }

    private function newPackWithMockServer(): Pack
    {
        $ref = new ReflectionClass(Pack::class);
        /** @var Pack $pack */
        $pack = $ref->newInstanceWithoutConstructor();
        $serverProp = $this->property($pack, 'server');
        $serverProp->setValue($pack, new class {
            public function exists(int $fd): bool
            {
                return false;
            }

            public function close(int $fd, bool $reset = false): bool
            {
                return true;
            }
        });

        return $pack;
    }

    private function forceUnpackType(string $type): void
    {
        $prop = new ReflectionProperty(Pack::class, 'unpackLengthType');
        $prop->setAccessible(true);
        $prop->setValue(null, $type);
    }

    private function resetPackStatics(): void
    {
        foreach (['unpackLengthType' => null, 'headerStruct' => ['length' => 'N'], 'headerLength' => 30, 'packetMaxlength' => 2 * 1024 * 1024, 'serializeType' => 'json'] as $name => $value) {
            $prop = new ReflectionProperty(Pack::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    private function property(object $object, string $name): ReflectionProperty
    {
        $class = $object;
        while ($class !== false) {
            $ref = new ReflectionClass($class);
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);

                return $prop;
            }
            $class = get_parent_class($class);
        }

        $this->fail("Property {$name} not found");
    }
}
