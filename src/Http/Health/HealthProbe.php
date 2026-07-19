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
        $checks = CheckFactory::fromDefs($this->config->livenessCheckDefs());
        if ($checks === []) {
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
        $checks = CheckFactory::fromDefs($this->config->readinessCheckDefs());
        if ($checks === []) {
            $checks = [new ProcessHealthCheck()];
        }

        return $this->run('readiness', $checks);
    }

    /**
     * 顺序执行全部检查；任一失败则整体 ok=false（AND）。
     *
     * 不并行：探针项通常很少，顺序更利于日志与超时可控；
     * 单项内部应自行限时，避免拖死整次探针。
     *
     * @param list<HealthCheckInterface> $checks
     */
    public function run(string $probe, array $checks): ProbeReport
    {
        $started = microtime(true);
        $results = [];
        $ok = true;

        foreach ($checks as $check) {
            $result = $check->check();
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
}
