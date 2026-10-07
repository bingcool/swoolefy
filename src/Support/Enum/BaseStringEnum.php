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
 * string Backed Enum 契约：展示文案、下拉 options、全部取值。
 *
 * 实现示例：
 *
 * enum OrderStatus: string implements BaseStringEnum
 * {
 *     use InteractsWithBackedEnumLabel;
 *     case Pending = 'pending';
 *     public function getLabel(): string { ... }
 * }
 */
interface BaseStringEnum
{
    public function getLabel(): string;

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array;

    /**
     * @return list<string>
     */
    public static function values(): array;
}
