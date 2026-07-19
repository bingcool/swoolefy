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

namespace Swoolefy\Http\Health;

/**
 * 单项健康检查结果（不可变值对象）。
 *
 * 由 {@see HealthCheckInterface::check()} 返回，再由 {@see ProbeReport} 汇总。
 */
final readonly class HealthCheckResult
{
    /**
     * @param string               $name    检查项名（与 {@see HealthCheckInterface::name()} 一致）
     * @param bool                 $ok      true=依赖可用；false=应使 readiness 失败
     * @param string               $message 人类可读说明（成功也可写简短状态如 pong）
     * @param array<string, mixed> $meta    附加诊断（latency_ms、component 等；可被 details 开关隐藏）
     */
    public function __construct(
        public string $name,
        public bool $ok,
        public string $message = '',
        public array $meta = [],
    ) {
    }

    /**
     * 序列化为探针 JSON 中的 checks[] 元素。
     *
     * status 使用 up/down（业界常见），与顶层 status 的 ok/unavailable 区分层级。
     *
     * @return array{name: string, status: string, message: string, meta?: array<string, mixed>}
     */
    public function toArray(bool $includeMeta = true): array
    {
        $row = [
            'name' => $this->name,
            'status' => $this->ok ? 'up' : 'down',
            'message' => $this->message,
        ];
        // meta 可能含主机/组件名；生产关闭 details 时不输出，降低信息泄露面
        if ($includeMeta && $this->meta !== []) {
            $row['meta'] = $this->meta;
        }

        return $row;
    }
}
