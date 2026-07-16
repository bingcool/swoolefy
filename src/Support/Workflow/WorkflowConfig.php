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

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\ApplicationConfig;

/**
 * Workflow 引擎配置加载器。
 *
 * 读取 APP_PATH/Config/workflow.php（可选），环境变量优先。
 *
 * 结构：
 *   workflow.default_run_store     — 默认 RunStore 别名（env WORKFLOW_RUN_STORE）
 *   workflow.run_stores[alias]     — 各存储连接参数；可选 driver，缺省时别名即驱动名
 *   workflow.condition_evaluator   — symfony | jsonlogic
 *
 * 模版：src/Stubs/workflow.conf.stub.php（create 命令复制到 Config/workflow.php）
 * 加载经 {@see ApplicationConfig::loadPhpConfig()}，不依赖 application.yaml。
 */
final class WorkflowConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('workflow.php'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测注入
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    public function workflowSection(): array
    {
        return (array) ($this->config['workflow'] ?? []);
    }

    /**
     * 默认 RunStore 别名（workflow.default_run_store）。
     * 未配置时回退 memory。
     */
    public function defaultRunStoreAlias(): string
    {
        $alias = ApplicationConfig::pickStringEnvFirst(
            $this->workflowSection(),
            'default_run_store',
            'WORKFLOW_RUN_STORE',
            '',
        );

        return $alias !== '' ? $alias : WorkflowRunStoreName::MEMORY;
    }

    /**
     * 已声明的 RunStore 配置表。
     *
     * @return array<string, array<string, mixed>>
     */
    public function runStores(): array
    {
        $raw = $this->workflowSection()['run_stores'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $stores = [];
        foreach ($raw as $alias => $section) {
            if (!is_string($alias) || $alias === '' || !is_array($section)) {
                continue;
            }
            $stores[$alias] = $section;
        }

        return $stores;
    }

    /**
     * @param string|null $alias null 时使用 defaultRunStoreAlias()
     *
     * @return array<string, mixed>
     */
    public function runStoreSection(?string $alias = null): array
    {
        $alias ??= $this->defaultRunStoreAlias();
        $section = $this->runStores()[$alias] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * 解析别名对应的驱动类型（memory | redis | db）。
     *
     * @param string|null $alias null 时使用 defaultRunStoreAlias()
     */
    public function runStoreDriver(?string $alias = null): string
    {
        $alias ??= $this->defaultRunStoreAlias();
        $section = $this->runStoreSection($alias);
        $driver = $section['driver'] ?? $alias;

        return is_string($driver) && $driver !== '' ? $driver : WorkflowRunStoreName::MEMORY;
    }

    public function hasRunStoreAlias(string $alias): bool
    {
        return isset($this->runStores()[$alias])
            || $alias === WorkflowRunStoreName::MEMORY;
    }

    public function conditionEvaluator(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->workflowSection(),
            'condition_evaluator',
            'WORKFLOW_CONDITION_EVALUATOR',
            'symfony',
        );
    }

    /** 节点默认超时秒数（0 表示不限制；生产建议 120）。 */
    public function defaultNodeTimeoutSeconds(): float
    {
        return max(0.0, (float) ApplicationConfig::pickIntEnvFirst(
            $this->workflowSection(),
            'default_node_timeout_seconds',
            'WORKFLOW_DEFAULT_NODE_TIMEOUT',
            120,
        ));
    }

    /** 审计日志组件别名（Config/component/log.php）。 */
    public function logComponent(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->workflowSection(),
            'log_component',
            'WORKFLOW_LOG_COMPONENT',
            'support_log',
        );
    }

    // --- redis section helpers ---

    public function redisComponent(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->runStoreSection($alias),
            'component',
            'WORKFLOW_REDIS_COMPONENT',
            WorkflowRunStoreName::REDIS,
        );
    }

    public function redisPrefix(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->runStoreSection($alias),
            'prefix',
            'WORKFLOW_REDIS_PREFIX',
            'workflow:run:',
        );
    }

    public function redisTtl(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->runStoreSection($alias),
            'ttl',
            'WORKFLOW_REDIS_TTL',
            0,
        );
    }

    // --- db section helpers ---

    public function dbComponent(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->runStoreSection($alias),
            'component',
            'WORKFLOW_DB_COMPONENT',
            WorkflowRunStoreName::DB,
        );
    }

    public function dbTable(?string $alias = null): string
    {
        $table = ApplicationConfig::pickStringEnvFirst(
            $this->runStoreSection($alias),
            'table',
            'WORKFLOW_DB_TABLE',
            'workflow_runs',
        );

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return 'workflow_runs';
        }

        return $table;
    }

    // --- HITL API 鉴权（resume / cancel / pause/tasks）---

    /** 是否启用 HITL HTTP 鉴权（workflow.hitl.auth_enabled / WORKFLOW_HITL_AUTH_ENABLED）。 */
    public function hitlAuthEnabled(): bool
    {
        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                $this->hitlSection(),
                'auth_enabled',
                'WORKFLOW_HITL_AUTH_ENABLED',
                '1',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /** 共享 API Key（Header X-Workflow-Api-Key 或 Body apiKey）。 */
    public function hitlApiKey(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->hitlSection(),
            'api_key',
            'WORKFLOW_HITL_API_KEY',
            '',
        );
    }

    /** 角色 Header 名，默认 X-Workflow-Role。 */
    public function hitlRoleHeader(): string
    {
        $header = ApplicationConfig::pickStringEnvFirst(
            $this->hitlSection(),
            'role_header',
            'WORKFLOW_HITL_ROLE_HEADER',
            WorkflowHitlAuth::DEFAULT_ROLE_HEADER,
        );

        return $header !== '' ? $header : WorkflowHitlAuth::DEFAULT_ROLE_HEADER;
    }

    /** 允许访问 HITL API 的角色列表（如 operator、admin）。 */
    public function hitlAllowedRoles(): array
    {
        $raw = $this->hitlSection()['allowed_roles'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $roles = [];
        foreach ($raw as $role) {
            if (is_string($role) && $role !== '') {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** resume 时 actor 是否须匹配 PauseNode assignee（admin 可豁免）。 */
    public function hitlRequireAssigneeMatch(): bool
    {
        $fromConfig = $this->hitlSection()['require_assignee_match'] ?? true;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['require_assignee_match' => $fromConfig],
                'require_assignee_match',
                'WORKFLOW_HITL_REQUIRE_ASSIGNEE_MATCH',
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /** @return array<string, mixed> */
    private function hitlSection(): array
    {
        return (array) ($this->workflowSection()['hitl'] ?? []);
    }
}

