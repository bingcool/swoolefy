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

namespace Swoolefy\Support\Job;

/** {@see JobHandlerInterface::handle()} 的返回结果。 */
final class JobResult
{
    public function __construct(
        public JobResultStatus $status,
        public ?string $error = null,
        public ?int $retryAfterMs = null,
    ) {
    }

    public static function success(): self
    {
        return new self(JobResultStatus::SUCCESS);
    }

    public static function retry(string $error, ?int $retryAfterMs = null): self
    {
        return new self(JobResultStatus::RETRY, $error, $retryAfterMs);
    }

    public static function fail(string $error): self
    {
        return new self(JobResultStatus::FAIL, $error);
    }

    public static function discard(?string $reason = null): self
    {
        return new self(JobResultStatus::DISCARD, $reason);
    }
}
