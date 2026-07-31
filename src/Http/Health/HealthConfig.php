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

use Swoolefy\Core\SystemEnv;
use Swoolefy\Support\ApplicationConfig;

/**
 * K8s HTTP 探针配置读取器（APP_PATH/Config/health.php）。
 *
 * ## 与 ProductionHealthCheck 的边界
 * | 类 | 时机 | 职责 |
 * |----|------|------|
 * | {@see \Swoolefy\Support\ProductionHealthCheck} | 部署前 CLI | Neuron/Workflow 配置与 Schema 体检 |
 * | 本配置 + {@see HealthProbe} | 运行期 HTTP | kubelet liveness / readiness |
 *
 * ## 配置优先级
 * 各字段：环境变量（`HEALTH_*`）→ PHP 配置 → 代码默认值（`ApplicationConfig::pick*EnvFirst`）。
 *
 * 模版：`src/Stubs/health.conf.stub.php`。
 */
final class HealthConfig
{
    /**
     * @param array<string, mixed> $config 完整配置（可含顶层 `health` 包装）
     */
    private function __construct(
        private readonly array $config,
    ) {
    }

    /**
     * 从应用 Config 目录加载 `health.php`。
     * 文件缺失时各 getter 走默认值（探针仍可注册默认路径）。
     */
    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('health.php'));
    }

    /**
     * 内存注入（单测），不读磁盘。
     *
     * @param array<string, mixed> $config
     *
     * @internal
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * 取出 `health` 段；若直接传扁平数组则原样使用。
     *
     * @return array<string, mixed>
     */
    private function section(): array
    {
        $section = $this->config['health'] ?? $this->config;

        return is_array($section) ? $section : [];
    }

    /**
     * 总开关。false 时 {@see HealthRoutes::register()} 不挂路由。
     *
     * env: `HEALTH_PROBE_ENABLED`
     */
    public function enabled(): bool
    {
        return ApplicationConfig::pickBoolEnvFirst(
            $this->section(),
            'enabled',
            'HEALTH_PROBE_ENABLED',
            true,
        );
    }

    /**
     * 主 liveness 路径（默认 `/health`）。
     * kubelet：`livenessProbe.httpGet.path`。
     *
     * env: `HEALTH_LIVENESS_PATH`
     */
    public function livenessPath(): string
    {
        return $this->normalizePath(ApplicationConfig::pickStringEnvFirst(
            $this->section(),
            'liveness_path',
            'HEALTH_LIVENESS_PATH',
            '/health',
        ));
    }

    /**
     * 主 readiness 路径（默认 `/ready`）。
     * kubelet：`readinessProbe.httpGet.path`。
     *
     * env: `HEALTH_READINESS_PATH`
     */
    public function readinessPath(): string
    {
        return $this->normalizePath(ApplicationConfig::pickStringEnvFirst(
            $this->section(),
            'readiness_path',
            'HEALTH_READINESS_PATH',
            '/ready',
        ));
    }

    /**
     * 需注册的全部 liveness 路径 = 主路径 + aliases（去重，主路径优先）。
     *
     * @return list<string>
     */
    public function livenessPaths(): array
    {
        return $this->mergePaths($this->livenessPath(), $this->aliasList('liveness'));
    }

    /**
     * @return list<string>
     */
    public function readinessPaths(): array
    {
        return $this->mergePaths($this->readinessPath(), $this->aliasList('readiness'));
    }

    /**
     * liveness 检查声明。空数组时 {@see HealthProbe::liveness()} 自动使用 ProcessHealthCheck。
     *
     * **生产建议保持为空**：liveness 绑 Redis/DB 会在依赖抖动时被 kubelet 杀 Pod（重启风暴）。
     *
     * @return list<array<string, mixed>>
     */
    public function livenessCheckDefs(): array
    {
        return $this->checkDefs('liveness_checks');
    }

    /**
     * readiness 检查声明（如 redis / database）。
     * 失败 → HTTP 503，kubelet 摘除 Endpoints，但**不**杀容器。
     *
     * @return list<array<string, mixed>>
     */
    public function readinessCheckDefs(): array
    {
        return $this->checkDefs('readiness_checks');
    }

    /**
     * 单项检查独立短超时（秒）。默认 2；须 > 0（支持小数）。
     *
     * env: `HEALTH_CHECK_TIMEOUT_SECONDS`
     * 单条 def 可用 `timeout_seconds` 覆盖。
     */
    public function checkTimeoutSeconds(): float
    {
        $section = $this->section();
        $env = env('HEALTH_CHECK_TIMEOUT_SECONDS');
        if ($env !== null && $env !== '' && is_numeric($env)) {
            $seconds = (float) $env;

            return $seconds > 0 ? $seconds : 2.0;
        }
        if (isset($section['check_timeout_seconds']) && is_numeric($section['check_timeout_seconds'])) {
            $seconds = (float) $section['check_timeout_seconds'];

            return $seconds > 0 ? $seconds : 2.0;
        }

        return 2.0;
    }

    /**
     * 响应是否包含 `checks` 明细。
     *
     * - 非生产：默认 true，便于本地排障
     * - 生产：读 `include_details_in_prd`（默认 false），避免泄露内部组件拓扑
     */
    public function includeDetails(): bool
    {
        $section = $this->section();
        if (SystemEnv::isPrdEnv()) {
            return ApplicationConfig::pickBoolEnvFirst(
                $section,
                'include_details_in_prd',
                'HEALTH_INCLUDE_DETAILS_IN_PRD',
                false,
            );
        }

        return ApplicationConfig::pickBoolEnvFirst(
            $section,
            'include_details',
            'HEALTH_INCLUDE_DETAILS',
            true,
        );
    }

    /**
     * 读取 aliases.liveness / aliases.readiness 路径列表。
     *
     * @return list<string>
     */
    private function aliasList(string $kind): array
    {
        $aliases = $this->section()['aliases'][$kind] ?? [];
        if (!is_array($aliases)) {
            return [];
        }

        $out = [];
        foreach ($aliases as $path) {
            if (is_string($path) && $path !== '') {
                $out[] = $this->normalizePath($path);
            }
        }

        return $out;
    }

    /**
     * 主路径在前，再拼 aliases，按 path 去重（保留首次出现顺序）。
     *
     * @param list<string> $extra
     * @return list<string>
     */
    private function mergePaths(string $primary, array $extra): array
    {
        $paths = [$primary, ...$extra];
        $unique = [];
        foreach ($paths as $path) {
            $unique[$path] = true;
        }

        return array_keys($unique);
    }

    /**
     * 过滤非法声明：必须是含 string `type` 的数组。
     *
     * @return list<array<string, mixed>>
     */
    private function checkDefs(string $key): array
    {
        $defs = $this->section()[$key] ?? [];
        if (!is_array($defs)) {
            return [];
        }

        $out = [];
        foreach ($defs as $def) {
            if (is_array($def) && isset($def['type']) && is_string($def['type'])) {
                $out[] = $def;
            }
        }

        return $out;
    }

    /**
     * 统一为以 `/` 开头的 path，避免路由注册与请求匹配不一致。
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        return '/' . ltrim($path, '/');
    }
}
