<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow;

use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;

/**
 * HITL API 鉴权 —— resume / cancel / listPauseTasks。
 *
 * 配置（workflow.php → workflow.hitl）：
 *   auth_enabled            — 是否启用（env WORKFLOW_HITL_AUTH_ENABLED）
 *   api_key                 — 共享密钥（Header X-Workflow-Api-Key）
 *   role_header             — 角色 Header 名，默认 X-Workflow-Role
 *   allowed_roles           — 允许的角色列表（含 admin 可跨 assignee）
 *   require_assignee_match  — resume 时 actor 须匹配任务 assignee（admin 除外）
 */
final class WorkflowHitlAuth
{
    public const DEFAULT_API_KEY_HEADER = 'X-Workflow-Api-Key';

    public const DEFAULT_ROLE_HEADER = 'X-Workflow-Role';

    public const ADMIN_ROLE = 'admin';

    public function __construct(
        private readonly WorkflowConfig $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->hitlAuthEnabled();
    }

    public function roleHeader(): string
    {
        return $this->config->hitlRoleHeader();
    }

    /**
     * 校验 API Key 或角色（auth_enabled 时至少满足其一）。
     *
     * @throws WorkflowPermissionException
     */
    public function assertAuthorized(?string $apiKeyHeader, ?string $role): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $expectedKey = $this->config->hitlApiKey();
        if ($expectedKey !== '' && is_string($apiKeyHeader) && hash_equals($expectedKey, $apiKeyHeader)) {
            return;
        }

        $allowedRoles = $this->config->hitlAllowedRoles();
        if ($allowedRoles !== [] && is_string($role) && $role !== '' && in_array($role, $allowedRoles, true)) {
            return;
        }

        throw new WorkflowPermissionException('Unauthorized workflow HITL request');
    }

    /**
     * resume 前校验 actor 是否有权处理该暂停任务。
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
     * listPauseTasks 时校验查询者只能看自己的任务（admin 可看全部）。
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

        if ($filterAssignee === null || $filterAssignee === '') {
            if (!is_string($actor) || $actor === '') {
                throw new WorkflowPermissionException('assignee filter or actor is required');
            }

            return;
        }

        if (!is_string($actor) || $actor === '' || $actor !== $filterAssignee) {
            throw new WorkflowPermissionException('Cannot list tasks for another assignee');
        }
    }

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
