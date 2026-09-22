<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core\Dto;

use PHPUintTest\TestCase;
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
