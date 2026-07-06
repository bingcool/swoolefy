<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;

/**
 * HITL（Human-in-the-Loop）HTTP API 鉴权器。
 *
 * 保护 resume / cancel / listPauseTasks 三类写/读敏感接口，防止未授权用户
 * 恢复他人任务、取消运行或窥探待办列表。
 *
 * 配置来源：Config/workflow.php → workflow.hitl（模版见 Stubs/workflow.conf.stub.php）
 *
 * | 配置项                   | 环境变量                          | 说明 |
 * |--------------------------|-----------------------------------|------|
 * | auth_enabled             | WORKFLOW_HITL_AUTH_ENABLED        | 总开关；false 时所有 assert* 直接放行 |
 * | api_key                  | WORKFLOW_HITL_API_KEY             | 共享密钥，经 Header 或 Body 传递 |
 * | role_header              | WORKFLOW_HITL_ROLE_HEADER         | 角色 Header 名，默认 X-Workflow-Role |
 * | allowed_roles            | —                                 | 允许的角色白名单 |
 * | require_assignee_match   | WORKFLOW_HITL_REQUIRE_ASSIGNEE_MATCH | resume 时 actor 须匹配任务 assignee |
 *
 * HTTP 控制器参考：Test/Module/Workflow/Controller/WorkflowController
 *
 * @see WorkflowConfig::hitlAuthEnabled()
 */
final class WorkflowHitlAuth
{
    /** API Key 默认 Header 名（Body 字段 apiKey 为备选）。 */
    public const DEFAULT_API_KEY_HEADER = 'X-Workflow-Api-Key';

    /** 角色默认 Header 名（Body 字段 role 为备选）。 */
    public const DEFAULT_ROLE_HEADER = 'X-Workflow-Role';

    /** 管理员角色：可跨 assignee resume / 查看全部 pause tasks。 */
    public const ADMIN_ROLE = 'admin';

    public function __construct(
        private readonly WorkflowConfig $config,
    ) {
    }

    /** 是否启用 HITL 鉴权（读取 workflow.hitl.auth_enabled）。 */
    public function isEnabled(): bool
    {
        return $this->config->hitlAuthEnabled();
    }

    /** 角色 Header 名（可配置，默认 {@see DEFAULT_ROLE_HEADER}）。 */
    public function roleHeader(): string
    {
        return $this->config->hitlRoleHeader();
    }

    /**
     * 第一层鉴权：校验调用方是否持有有效 API Key 或允许的角色。
     *
     * 规则（auth_enabled=true 时）：
     *   1. apiKeyHeader 与配置的 api_key 完全一致（hash_equals 防时序攻击）→ 通过
     *   2. role 非空且在 allowed_roles 内 → 通过
     *   3. 否则 → WorkflowPermissionException(403)
     *
     * auth_enabled=false 时直接 return，便于开发 / 单测。
     *
     * @param string|null $apiKeyHeader 来自 X-Workflow-Api-Key 或 Body apiKey
     * @param string|null $role         来自 role_header 或 Body role
     *
     * @throws WorkflowPermissionException
     */
    public function assertAuthorized(?string $apiKeyHeader, ?string $role): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // 路径 1：共享 API Key（适合服务间调用 / 运维脚本）
        $expectedKey = $this->config->hitlApiKey();
        if ($expectedKey !== '' && is_string($apiKeyHeader) && hash_equals($expectedKey, $apiKeyHeader)) {
            return;
        }

        // 路径 2：基于角色的访问（适合前端按岗位传 X-Workflow-Role）
        $allowedRoles = $this->config->hitlAllowedRoles();
        if ($allowedRoles !== [] && is_string($role) && $role !== '' && in_array($role, $allowedRoles, true)) {
            return;
        }

        throw new WorkflowPermissionException('Unauthorized workflow HITL request');
    }

    /**
     * resume 前的完整鉴权：身份 + assignee 归属。
     *
     * 执行顺序：
     *   1. assertAuthorized — 基础身份
     *   2. require_assignee_match=false → 直接放行
     *   3. Run 非 WAITING → 跳过 assignee 校验（Engine 会另行报错）
     *   4. role=admin → 可处理任意 assignee 的任务
     *   5. 从 PauseNode 输出读取 assignee，与 actor 精确匹配
     *
     * @param string|null $actor 实际操作人，来自 Body actor 或 assignee
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanResume(WorkflowRun $run, ?string $apiKeyHeader, ?string $actor, ?string $role): void
    {
        $this->assertAuthorized($apiKeyHeader, $role);

        if (!$this->config->hitlRequireAssigneeMatch()) {
            return;
        }

        if ($run->status !== RunStatus::WAITING) {
            return;
        }

        if (is_string($role) && $role === self::ADMIN_ROLE) {
            return;
        }

        $taskAssignee = $this->resolveTaskAssignee($run);
        // PauseNode 未配置 assignee 时不做额外限制
        if ($taskAssignee === null) {
            return;
        }

        if (!is_string($actor) || $actor === '' || $actor !== $taskAssignee) {
            throw new WorkflowPermissionException(
                "Assignee mismatch: task is assigned to [{$taskAssignee}]",
            );
        }
    }

    /**
     * listPauseTasks 前的鉴权：限制查询范围，防止横向越权。
     *
     * 规则（auth_enabled=true 且非 admin）：
     *   - 带 assignee 过滤：actor 必须等于 filterAssignee
     *   - 不带 assignee 过滤：必须提供 actor（只查自己的任务）
     *
     * admin 角色或 auth 未启用时不限制查询范围。
     *
     * @param string|null $filterAssignee Query assignee 参数
     * @param string|null $actor          当前操作人
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanListTasks(?string $filterAssignee, ?string $apiKeyHeader, ?string $actor, ?string $role): void
    {
        $this->assertAuthorized($apiKeyHeader, $role);

        if (!$this->isEnabled()) {
            return;
        }

        if (is_string($role) && $role === self::ADMIN_ROLE) {
            return;
        }

        // 未指定 assignee 过滤：要求 actor 自证身份；控制器须用 {@see resolveListAssigneeFilter()} 传给引擎
        if ($filterAssignee === null || $filterAssignee === '') {
            if (!is_string($actor) || $actor === '') {
                throw new WorkflowPermissionException('assignee filter or actor is required');
            }

            return;
        }

        // 指定了 assignee 过滤：actor 必须与之一致，禁止查他人队列
        if (!is_string($actor) || $actor === '' || $actor !== $filterAssignee) {
            throw new WorkflowPermissionException('Cannot list tasks for another assignee');
        }
    }

    /**
     * 计算传给 {@see \Swoolefy\Support\Workflow\Engine\WorkflowEngine::listPauseTasks()} 的 assignee 过滤值。
     *
     * 须在 {@see assertCanListTasks()} 通过后调用：
     *   - 有 query assignee → 原样使用
     *   - auth 关闭或 admin → null（查全部）
     *   - 否则 → actor（仅查本人待办）
     */
    public function resolveListAssigneeFilter(?string $queryAssignee, ?string $actor, ?string $role): ?string
    {
        if ($queryAssignee !== null && $queryAssignee !== '') {
            return $queryAssignee;
        }

        if (!$this->isEnabled()) {
            return null;
        }

        if (is_string($role) && $role === self::ADMIN_ROLE) {
            return null;
        }

        return $actor;
    }

    /**
     * 从 PauseNode 执行输出中提取任务处理人。
     *
     * PauseNode 成功时会写入 nodeOutputs[pauseNodeId].assignee，
     * listPauseTasks / resume 鉴权均依赖此字段。
     */
    private function resolveTaskAssignee(WorkflowRun $run): ?string
    {
        if ($run->pauseNodeId === null || $run->pauseNodeId === '') {
            return null;
        }

        $output = $run->state->outputOf($run->pauseNodeId) ?? [];
        $assignee = is_array($output) ? ($output['assignee'] ?? null) : null;

        return is_string($assignee) && $assignee !== '' ? $assignee : null;
    }
}
