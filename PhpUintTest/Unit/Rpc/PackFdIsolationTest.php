<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Rpc;

use PhpUintTest\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Swoolefy\Rpc\Pack;

/**
 * 阶段一 P0-3.1（审计项 1）：TCP/RPC 半包缓存按 fd 隔离。
 * 覆盖连接级 delete 与进程级 destroy 的边界，防止单连接 close 误清全部半包。
 */
final class PackFdIsolationTest extends TestCase
{
    /**
     * 测 delete($fd) 只清目标 fd，且重复删除幂等不抛异常、不影响其他 fd。
     * 对应问题：连接 close 若误用 destroy() 会清空全部半包并牵连断连。
     */
    public function testDeleteOnlyClearsTargetFdAndIsIdempotent(): void
    {
        $pack = $this->newPackWithoutConstructor();
        $buffers = $this->property($pack, '_buffers');
        $headers = $this->property($pack, '_headers');

        $buffers->setValue($pack, [
            1 => 'partial-one',
            2 => 'partial-two',
        ]);
        $headers->setValue($pack, [
            1 => ['length' => 10],
            2 => ['length' => 20],
        ]);

        $this->assertTrue($pack->delete(1));
        $this->assertArrayNotHasKey(1, $buffers->getValue($pack));
        $this->assertArrayNotHasKey(1, $headers->getValue($pack));
        $this->assertSame('partial-two', $buffers->getValue($pack)[2]);
        $this->assertSame(['length' => 20], $headers->getValue($pack)[2]);

        $this->assertTrue($pack->delete(1));
        $this->assertSame('partial-two', $buffers->getValue($pack)[2]);
    }

    /**
     * 测 destroy() 清空全部半包缓存（WorkerStop 进程级入口语义）。
     * 对应问题：Worker 退出时仍需完整清理，避免半包残留内存。
     */
    public function testDestroyClearsAllPartialBuffers(): void
    {
        $pack = $this->newPackWithoutConstructor();
        $buffers = $this->property($pack, '_buffers');
        $headers = $this->property($pack, '_headers');

        $buffers->setValue($pack, [
            3 => 'a',
            4 => 'b',
        ]);
        $headers->setValue($pack, [
            3 => ['length' => 1],
            4 => ['length' => 2],
        ]);

        $server = new class {
            public function exists(int $fd): bool
            {
                return false;
            }

            public function close(int $fd, bool $reset = false): bool
            {
                return true;
            }
        };

        $serverProp = $this->property($pack, 'server');
        $serverProp->setValue($pack, $server);

        $this->assertTrue($pack->destroy());
        $this->assertSame([], $buffers->getValue($pack));
        $this->assertSame([], $headers->getValue($pack));
    }

    private function newPackWithoutConstructor(): Pack
    {
        $ref = new ReflectionClass(Pack::class);

        /** @var Pack $pack */
        $pack = $ref->newInstanceWithoutConstructor();

        return $pack;
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
