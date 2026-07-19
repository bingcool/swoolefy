<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Http;

use PhpUintTest\TestCase;
use PhpUintTest\Unit\Http\Support\SampleCreateUserRequest;
use Swoolefy\Http\BasePageRequest;
use Swoolefy\Http\BaseRequest;

/**
 * BaseRequest：validated / only / 类型助手。
 */
final class BaseRequestTest extends TestCase
{
    /**
     * 验证：Sample / BasePageRequest 均继承 BaseRequest。
     */
    public function testInheritance(): void
    {
        $this->assertTrue(is_subclass_of(BasePageRequest::class, BaseRequest::class));
        $this->assertTrue(is_subclass_of(SampleCreateUserRequest::class, BaseRequest::class));
    }

    /**
     * 验证：validated() 返回 hydrate 后字段，且不含 requestInput。
     */
    public function testValidatedReturnsHydratedFieldsWithoutRequestInput(): void
    {
        $req = (new SampleCreateUserRequest())
            ->setUsername('alice')
            ->setAge(18)
            ->setEnabled(true);

        $data = $req->validatedData();
        $this->assertSame('alice', $data['username'] ?? null);
        $this->assertSame(18, $data['age'] ?? null);
        $this->assertTrue($data['enabled'] ?? false);
        $this->assertArrayNotHasKey('requestInput', $data);

        $this->assertSame('alice', $req->getUsername());
        $this->assertSame('fallback', $data['missing'] ?? 'fallback');
    }

    /**
     * 验证：only / except。
     */
    public function testOnlyAndExcept(): void
    {
        $req = (new SampleCreateUserRequest())
            ->setUsername('bob')
            ->setAge(20)
            ->setEnabled(false);

        $this->assertSame(
            ['username' => 'bob', 'age' => 20],
            $req->only('username', 'age')
        );
        $except = $req->except('enabled');
        $this->assertArrayHasKey('username', $except);
        $this->assertArrayHasKey('age', $except);
        $this->assertArrayNotHasKey('enabled', $except);
    }

    /**
     * 验证：has / filled / missing。
     */
    public function testHasFilledMissing(): void
    {
        $req = (new SampleCreateUserRequest())
            ->setUsername('  ')
            ->setAge(null);

        $this->assertTrue($req->has('username'));
        $this->assertFalse($req->filled('username'));
        $this->assertTrue($req->has('age'));
        $this->assertFalse($req->filled('age'));
        $this->assertTrue($req->missing('notDeclared'));

        $req->setUsername('carol');
        $this->assertTrue($req->filled('username'));
    }

    /**
     * 验证：boolean / integer / string 助手。
     */
    public function testTypedHelpers(): void
    {
        $req = (new SampleCreateUserRequest())
            ->setUsername('dave')
            ->setEnabled(true)
            ->setAge(21);

        $this->assertTrue($req->boolean('enabled'));
        $this->assertSame(21, $req->integer('age'));
        $this->assertSame('dave', $req->string('username'));
        $this->assertNull($req->integer('notDeclared'));
        $this->assertSame(9, $req->integer('notDeclared', 9));
        $this->assertFalse($req->boolean('notDeclared'));
        $this->assertTrue($req->boolean('notDeclared', true));
    }

    /**
     * 验证：BasePageRequest 可使用 validated()。
     */
    public function testBasePageRequestValidatedIncludesPageFields(): void
    {
        $req = (new BasePageRequest())
            ->setPage(2)
            ->setPageSize(50);

        $data = $req->validatedData();
        $this->assertSame(2, $data['page'] ?? null);
        $this->assertSame(50, $data['pageSize'] ?? null);
        $this->assertSame(2, $req->integer('page'));
    }
}
