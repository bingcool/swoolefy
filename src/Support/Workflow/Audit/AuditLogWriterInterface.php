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

namespace Swoolefy\Support\Workflow\Audit;

/**
 * 审计日志写入接口 —— 可替换为 DB / ELK 实现。
 */
interface AuditLogWriterInterface
{
    /** @param array<string, mixed> $context */
    public function write(string $event, array $context): void;
}
