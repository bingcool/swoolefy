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

namespace Swoolefy\Support\Claim;

use Swoolefy\Exception\AbstractSwoolefyExeption;

/**
 * claim 模块的异常。
 *
 * Go 版本用若干 sentinel error 区分「租约丢失」等状态；PHP 用同一个异常类的
 * code 常量表达，调用方通过 {@see isLeaseLost()} 判断。租约丢失不是普通失败：
 * 业务副作用可能已经发生，绝不能因为收到这个异常就重试。
 */
class ClaimException extends AbstractSwoolefyExeption
{
    public const DB_REQUIRED = 1;

    public const JOB_REQUIRED = 2;

    public const INVALID_TABLE = 3;

    public const OWNER_REQUIRED = 4;

    public const INVALID_TASK = 5;

    public const LEASE_LOST = 6;

    public const HANDLER_REQUIRED = 7;

    public const DB_ERROR = 8;

    public static function dbRequired(): self
    {
        return new self('claim: database is required', self::DB_REQUIRED);
    }

    public static function jobRequired(): self
    {
        return new self('claim: job is required', self::JOB_REQUIRED);
    }

    public static function invalidTable(string $table): self
    {
        return new self(
            'claim: table must be a plain identifier, optionally schema-qualified: ' . $table,
            self::INVALID_TABLE,
        );
    }

    public static function ownerRequired(): self
    {
        return new self('claim: owner is required', self::OWNER_REQUIRED);
    }

    public static function handlerRequired(): self
    {
        return new self('claim: runner handler is required', self::HANDLER_REQUIRED);
    }

    public static function invalidTask(int $rowId): self
    {
        return new self(
            'claim: task must carry the owner and attempts returned by claim: row ' . $rowId,
            self::INVALID_TASK,
        );
    }

    /**
     * 租约丢失：本次认领已经被别的 owner 或另一次认领取代。
     * 收尾 SQL 的 owner + status + attempts 围栏没匹配到行，因此什么都没写。
     */
    public static function leaseLost(int $rowId): self
    {
        return new self('claim: lease lost: row ' . $rowId, self::LEASE_LOST);
    }

    /**
     * 包装 PDO / Query 抛出的异常，保留错误阶段与原始异常。
     */
    public static function dbError(string $stage, \Throwable $previous): self
    {
        return new self('claim: ' . $stage . ': ' . $previous->getMessage(), self::DB_ERROR, $previous);
    }

    public static function isLeaseLost(\Throwable $e): bool
    {
        return $e instanceof self && $e->getCode() === self::LEASE_LOST;
    }
}
