<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Core;

use PhpUintTest\TestCase;
use Swoolefy\Core\ComponentTrait;

/**
 * 阶段二 4.9（审计项 37）：ComponentTrait 容器魔术方法直接操作 containers。
 * 目标：isset/unset 不递归触发魔术属性，unset 后可重新获取。
 */
final class ComponentTraitMagicTest extends TestCase
{
    /**
     * 测存在、不存在、unset 后容器项被删除。
     * 对应问题：__isset/__unset 访问 $this->$name 会再次进入魔术方法。
     */
    public function testIssetAndUnsetOperateContainersDirectly(): void
    {
        $obj = new class {
            use ComponentTrait;

            public function seed(string $name, object $value): void
            {
                $this->containers[$name] = $value;
            }

            public function rawContainers(): array
            {
                return $this->containers;
            }
        };

        $this->assertFalse(isset($obj->db));
        $obj->seed('db', new \stdClass());
        $this->assertTrue(isset($obj->db));
        unset($obj->db);
        $this->assertFalse(isset($obj->db));
        $this->assertArrayNotHasKey('db', $obj->rawContainers());
    }
}
