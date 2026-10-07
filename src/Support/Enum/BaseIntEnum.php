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

namespace Swoolefy\Support\Enum;

/**
 * int Backed Enum 契约：展示文案、下拉 options、全部取值。
 *
 * 实现示例：
 *
 * enum Priority: int implements BaseIntEnum
 * {
 *     use InteractsWithBackedEnumLabel;
 *     case Low = 1;
 *     public function getLabel(): string { ... }
 * }
 */
interface BaseIntEnum
{
    public function getLabel(): string;

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array;

    /**
     * @return list<int>
     */
    public static function values(): array;
}
