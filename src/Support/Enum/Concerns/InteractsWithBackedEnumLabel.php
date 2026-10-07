<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Enum\Concerns;

/**
 * 为 string/int Backed Enum 提供 {@see options()}、{@see values()} 默认实现（需自行实现 {@see getLabel()}）。
 *
 * @mixin \UnitEnum&\BackedEnum
 */
trait InteractsWithBackedEnumLabel
{
    public static function options(): array
    {
        return array_map(
            static fn ($case) => [
                'value' => $case->value,
                'label' => $case->getLabel(),
            ],
            self::cases(),
        );
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
