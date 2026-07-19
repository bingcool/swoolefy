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
 * 一次 liveness / readiness 探针的汇总报告。
 *
 * {@see HealthController} 据此设置 HTTP 200/503，并将 {@see toArray()} 放入响应 data。
 */
final class ProbeReport
{
    /**
     * @param string                  $probe      `liveness` | `readiness`
     * @param bool                    $ok         全部 checks 通过才为 true（AND 语义）
     * @param list<HealthCheckResult> $checks     各项结果（顺序与配置一致）
     * @param float                   $durationMs 整次探针墙钟耗时（毫秒）
     * @param string                  $timestamp  ISO8601（UTC，`gmdate('c')`）
     */
    public function __construct(
        public readonly string $probe,
        public readonly bool $ok,
        public readonly array $checks,
        public readonly float $durationMs,
        public readonly string $timestamp,
    ) {
    }

    /**
     * 转为 Controller 响应 data。
     *
     * | 字段 | 含义 |
     * |------|------|
     * | status | ok / unavailable（kubelet 主要看 HTTP status，此字段便于人工排障） |
     * | probe | liveness / readiness |
     * | checks | 可选；由 {@see HealthConfig::includeDetails()} 控制 |
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $includeDetails = true): array
    {
        $payload = [
            'status' => $this->ok ? 'ok' : 'unavailable',
            'probe' => $this->probe,
            'timestamp' => $this->timestamp,
            'duration_ms' => round($this->durationMs, 2),
        ];

        if ($includeDetails) {
            $payload['checks'] = array_map(
                static fn (HealthCheckResult $c): array => $c->toArray(true),
                $this->checks,
            );
        }

        return $payload;
    }
}
