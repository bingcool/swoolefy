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

namespace Swoolefy\Support\Rag\Store;

use InvalidArgumentException;

/**
 * Milvus 布尔过滤表达式安全构造（防注入）。
 *
 * 仅用于 metadata JSON 字段等值匹配；值经转义后包在双引号内。
 */
final class MilvusFilterExpr
{
    private const MAX_VALUE_LENGTH = 2048;

    /**
     * 按 sourceType / sourceName 构造 deleteBy 过滤表达式。
     */
    public static function deleteBySourceFilter(string $sourceType, ?string $sourceName = null): string
    {
        $filter = 'metadata["sourceType"] == ' . self::quoteString($sourceType);
        if ($sourceName !== null) {
            $filter .= ' and metadata["sourceName"] == ' . self::quoteString($sourceName);
        }

        return $filter;
    }

    /**
     * 转义并包裹 Milvus 字符串字面量（双引号）。
     */
    public static function quoteString(string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException('Milvus filter string value must not be empty');
        }

        if (preg_match('/[\x00-\x1f\x7f]/u', $value) === 1) {
            throw new InvalidArgumentException('Milvus filter string value contains invalid control characters');
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException(
                'Milvus filter string value exceeds maximum length of ' . self::MAX_VALUE_LENGTH,
            );
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }
}
