<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Enum;

use PHPUnit\Framework\TestCase;
use Swoolefy\Support\Enum\BaseIntEnum;
use Swoolefy\Support\Enum\BaseStringEnum;
use Swoolefy\Support\Enum\Concerns\InteractsWithBackedEnumLabel;

final class BackedEnumLabelTest extends TestCase
{
    public function testStringBackedOptionsAndValues(): void
    {
        $this->assertSame(
            [
                ['value' => 'a', 'label' => 'Alpha'],
                ['value' => 'b', 'label' => 'Beta'],
            ],
            SampleStringEnum::options(),
        );
        $this->assertSame(['a', 'b'], SampleStringEnum::values());
        $this->assertSame('Alpha', SampleStringEnum::A->getLabel());
    }

    public function testIntBackedOptionsAndValues(): void
    {
        $this->assertSame(
            [
                ['value' => 1, 'label' => 'One'],
                ['value' => 2, 'label' => 'Two'],
            ],
            SampleIntEnum::options(),
        );
        $this->assertSame([1, 2], SampleIntEnum::values());
    }
}

enum SampleStringEnum: string implements BaseStringEnum
{
    use InteractsWithBackedEnumLabel;

    case A = 'a';
    case B = 'b';

    public function getLabel(): string
    {
        return match ($this) {
            self::A => 'Alpha',
            self::B => 'Beta',
        };
    }
}

enum SampleIntEnum: int implements BaseIntEnum
{
    use InteractsWithBackedEnumLabel;

    case One = 1;
    case Two = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::One => 'One',
            self::Two => 'Two',
        };
    }
}
