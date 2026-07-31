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

use Swoolefy\Http\Health\Check\ProcessHealthCheck;
use Swoolefy\Support\Workflow\Engine\TimeoutGuard;
use Swoolefy\Support\Workflow\Exception\WorkflowTimeoutException;
use Throwable;

/**
 * K8s 风格 HTTP 探针执行器。
 *
 * ## 语义（Kubernetes）
 * | 方法 | kubelet | 失败后果 |
 * |------|---------|----------|
 * | {@see liveness()} | livenessProbe | 超时/失败多次后**重启容器** |
 * | {@see readiness()} | readinessProbe | 从 Service Endpoints **摘除**，不杀进程 |
 *
 * 因此 liveness 应尽量无外部 I/O；依赖检查放在 readiness。
 * 单项检查经 TimeoutGuard 包裹；readiness 任一必需项超时即整体失败。
 *
 * 与 {@see \Swoolefy\Support\ProductionHealthCheck}（部署前配置体检）互补。
 */
final class HealthProbe
{
    public function __construct(
        private readonly HealthConfig $config,
    ) {
    }

    /**
     * 从应用配置构造；单测可传入 {@see HealthConfig::fromArray()}。
     */
    public static function fromConfig(?HealthConfig $config = null): self
    {
        return new self($config ?? HealthConfig::load());
    }

    public function config(): HealthConfig
    {
        return $this->config;
    }

    /**
     * 执行 liveness 检查集。
     *
     * 配置为空时自动附带 {@see ProcessHealthCheck}：
     * Worker 能跑到本方法即证明进程存活（适合作为默认 liveness）。
     */
    public function liveness(): ProbeReport
    {
        $timeout = $this->config->checkTimeoutSeconds();
        $checks = CheckFactory::fromDefs($this->config->livenessCheckDefs(), $timeout);
        if ($checks === []) {
            // liveness 默认仅进程自身状态，避免依赖抖动杀 Pod
            $checks = [new ProcessHealthCheck()];
        }

        return $this->run('liveness', $checks);
    }

    /**
     * 执行 readiness 检查集。
     *
     * 配置为空时同样回落 ProcessHealthCheck（「无依赖声明 = 进程可服务即 ready」）。
     * 生产一般至少配置 redis / database。
     */
    public function readiness(): ProbeReport
    {
        $timeout = $this->config->checkTimeoutSeconds();
        $checks = CheckFactory::fromDefs($this->config->readinessCheckDefs(), $timeout);
        if ($checks === []) {
            $checks = [new ProcessHealthCheck()];
        }

        return $this->run('readiness', $checks);
    }

    /**
     * 顺序执行全部检查；任一失败/超时则整体 ok=false（AND）。
     *
     * 响应字段仅含名称、状态、耗时与错误分类，不暴露 DSN/凭证。
     *
     * @param list<HealthCheckInterface> $checks
     */
    public function run(string $probe, array $checks): ProbeReport
    {
        $started = microtime(true);
        $results = [];
        $ok = true;
        $timeout = $this->config->checkTimeoutSeconds();

        foreach ($checks as $check) {
            $result = $this->runOne($check, $timeout);
            $results[] = $result;
            if (!$result->ok) {
                // 继续跑完其余项，便于一次响应看到全部 down 原因（排障）
                $ok = false;
            }
        }

        return new ProbeReport(
            probe: $probe,
            ok: $ok,
            checks: $results,
            durationMs: (microtime(true) - $started) * 1000,
            timestamp: gmdate('c'),
        );
    }

    /**
     * 单项检查：TimeoutGuard + 结果脱敏。
     */
    private function runOne(HealthCheckInterface $check, float $timeoutSeconds): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $raw = TimeoutGuard::run(
                fn (): HealthCheckResult => $check->check(),
                $timeoutSeconds,
            );

            return $this->sanitize($raw, (microtime(true) - $started) * 1000);
        } catch (WorkflowTimeoutException) {
            // readiness 必需项超时 → ok=false；liveness 同理（若误挂慢依赖）
            return new HealthCheckResult(
                name: $check->name(),
                ok: false,
                message: 'timeout',
                meta: [
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'error_category' => 'timeout',
                ],
            );
        } catch (Throwable) {
            return new HealthCheckResult(
                name: $check->name(),
                ok: false,
                message: 'check_failed',
                meta: [
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'error_category' => 'exception',
                ],
            );
        }
    }

    /**
     * 只保留名称 / 状态 / 耗时 / 错误分类，剥离可能含连接信息的 message/meta。
     */
    private function sanitize(HealthCheckResult $result, float $durationMs): HealthCheckResult
    {
        $category = 'ok';
        if (!$result->ok) {
            $category = is_string($result->meta['error_category'] ?? null)
                ? (string) $result->meta['error_category']
                : 'failed';
        }
        $duration = isset($result->meta['duration_ms']) && is_numeric($result->meta['duration_ms'])
            ? (float) $result->meta['duration_ms']
            : $durationMs;

        return new HealthCheckResult(
            name: $result->name,
            ok: $result->ok,
            message: $result->ok ? 'up' : $category,
            meta: [
                'duration_ms' => round($duration, 2),
                'error_category' => $category,
            ],
        );
    }
}
