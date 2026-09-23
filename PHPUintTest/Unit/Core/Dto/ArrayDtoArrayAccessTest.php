<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core\Dto;

use LogicException;
use PHPUintTest\TestCase;
use ReflectionProperty;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\ArrayList;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Http\BaseResponse;

final class ArrayDtoArrayAccessTest extends TestCase
{
    public function testAbstractDtoPropertyAndArrayAccessAreEquivalent(): void
    {
        $dto = SampleLeafDto::fromArray(['name' => 'cron', 'count' => 3]);

        $this->assertSame('cron', $dto->name);
        $this->assertSame('cron', $dto['name']);
        $this->assertSame(3, $dto['count']);
        $this->assertTrue(isset($dto['name']));
    }

    public function testNestedDtoAndArrayListHydration(): void
    {
        $root = SampleRootDto::fromArray([
            'title' => 'nodes',
            'items' => [
                ['name' => 'a', 'count' => 1],
                ['name' => 'b', 'count' => 2],
            ],
        ]);

        $this->assertSame('nodes', $root['title']);
        $this->assertCount(2, $root['items']);
        $this->assertInstanceOf(SampleLeafDto::class, $root['items'][0]);
        $this->assertSame('a', $root['items'][0]['name']);
    }

    public function testJsonSerializeAndIteratorMatchDeepArray(): void
    {
        $dto = SampleRootDto::fromArray([
            'title' => 't',
            'items' => [['name' => 'x', 'count' => 9]],
        ]);

        $this->assertSame($dto->toDeepArray(), $dto->jsonSerialize());
        $this->assertSame($dto->toDeepArray(), iterator_to_array($dto));
        $this->assertSame($dto->toDeepArray(), json_decode(json_encode($dto), true));
    }

    public function testBaseResponseDataUsesGetter(): void
    {
        $response = new SampleEnvelopeResponse();
        $response->setData(['ok' => true]);

        $this->assertSame(['ok' => true], $response->getData());
        $this->assertSame(['ok' => true], $response['data']);
        $this->assertSame(['ok' => true], $response->data);
        $this->assertSame(0, $response['code']);
    }

    public function testOffsetUnsetUnknownFieldDoesNotRecurse(): void
    {
        $dto = new SampleLeafDto();
        unset($dto['unknown']);
        $this->addToAssertionCount(1);
    }

    public function testFromArrayHydratesParentPrivateProperty(): void
    {
        $dto = SampleChildDto::fromArray([
            'parentName' => 'parent',
            'childName' => 'child',
        ]);

        $this->assertSame('parent', $dto['parentName']);
        $this->assertSame('child', $dto['childName']);
    }

    public function testFromArrayHydratesMultiLevelInheritance(): void
    {
        $dto = SampleGrandChildDto::fromArray([
            'grandPublic' => 'gp',
            'grandProtected' => 'gpr',
            'grandPrivate' => 'gpi',
            'parentProtected' => 'pp',
            'parentPrivate' => 'pri',
            'childName' => 'c',
        ]);

        $this->assertSame('gp', $dto['grandPublic']);
        $this->assertSame('gpr', $dto['grandProtected']);
        $this->assertSame('gpi', $dto['grandPrivate']);
        $this->assertSame('pp', $dto['parentProtected']);
        $this->assertSame('pri', $dto['parentPrivate']);
        $this->assertSame('c', $dto['childName']);
    }

    public function testOffsetUnsetMakesPropertyUninitialized(): void
    {
        $dto = new SampleUnsetDto();
        $this->assertTrue(isset($dto['name']));

        unset($dto['name']);

        $prop = new ReflectionProperty(SampleUnsetDto::class, 'name');
        $prop->setAccessible(true);
        $this->assertFalse($prop->isInitialized($dto));
        $this->assertFalse(isset($dto['name']));
        $this->assertNull($dto['name']);
    }

    public function testOffsetUnsetNullablePropertyBecomesUninitializedNotNull(): void
    {
        $dto = new SampleNullableUnsetDto();
        unset($dto['label']);

        $prop = new ReflectionProperty(SampleNullableUnsetDto::class, 'label');
        $prop->setAccessible(true);
        $this->assertFalse($prop->isInitialized($dto));
        $this->assertFalse(isset($dto['label']));
    }

    public function testOffsetUnsetThenSetReinitializesProperty(): void
    {
        $dto = new SampleUnsetDto();
        unset($dto['name']);
        $dto['name'] = 'new';

        $this->assertSame('new', $dto['name']);
        $this->assertTrue(isset($dto['name']));
    }

    public function testOffsetUnsetParentPrivateProperty(): void
    {
        $dto = new SampleChildDto();
        unset($dto['parentName']);
        $dto['parentName'] = 'bar';

        $this->assertSame('bar', $dto['parentName']);
    }

    public function testOffsetUnsetReadonlyThrowsLogicException(): void
    {
        $dto = new SampleReadonlyDto('x');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('只读字段不可 unset');
        unset($dto['id']);
    }

    public function testOffsetExistsFalseForNullInitializedProperty(): void
    {
        $dto = new SampleNullableUnsetDto();
        $dto['label'] = null;

        $this->assertNull($dto['label']);
        $this->assertFalse(isset($dto['label']));
    }

    public function testOffsetExistsFalseWhenGetterReturnsNull(): void
    {
        $dto = new SampleGetterNullDto();

        $this->assertNull($dto['alias']);
        $this->assertFalse(isset($dto['alias']));
    }

    public function testOffsetGetPrefersDeclaredPropertyOverGetter(): void
    {
        $dto = new SamplePropertyGetterConflictDto();

        $this->assertSame('from-property', $dto['name']);
    }
}

class SampleLeafDto extends AbstractDto
{
    #[ApiProperty]
    protected string $name = '';

    #[ApiProperty]
    protected int $count = 0;
}

class SampleRootDto extends AbstractDto
{
    #[ApiProperty]
    protected string $title = '';

    /**
     * @var array<int, SampleLeafDto>
     */
    #[ApiProperty]
    #[ArrayList(itemClass: SampleLeafDto::class)]
    protected array $items = [];
}

class SampleEnvelopeResponse extends BaseResponse
{
    public function getData(): array
    {
        $data = parent::getData();

        return is_array($data) ? $data : [];
    }
}

class SampleParentDto extends AbstractDto
{
    private string $parentName = '';
}

class SampleChildDto extends SampleParentDto
{
    private string $childName = '';
}

class SampleGrandParentDto extends AbstractDto
{
    public string $grandPublic = '';

    protected string $grandProtected = '';

    private string $grandPrivate = '';
}

class SampleGrandParentMiddleDto extends SampleGrandParentDto
{
    protected string $parentProtected = '';

    private string $parentPrivate = '';
}

class SampleGrandChildDto extends SampleGrandParentMiddleDto
{
    private string $childName = '';
}

class SampleUnsetDto extends AbstractDto
{
    protected string $name = 'foo';
}

class SampleNullableUnsetDto extends AbstractDto
{
    protected ?string $label = 'foo';
}

class SampleReadonlyDto extends AbstractDto
{
    public function __construct(
        private readonly string $id,
    ) {
    }
}

class SampleGetterNullDto extends AbstractDto
{
    public function getAlias(): ?string
    {
        return null;
    }
}

class SamplePropertyGetterConflictDto extends AbstractDto
{
    protected string $name = 'from-property';

    public function getName(): string
    {
        return 'from-getter';
    }
}
