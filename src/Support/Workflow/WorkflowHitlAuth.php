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

use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;

/**
 * HITL（Human-in-the-Loop）HTTP API 鉴权器。
 *
 * 保护 resume / cancel / listPauseTasks / status / events 等敏感接口，
 * 防止未授权恢复他人任务、取消运行或窥探待办。
 *
 * ## 与统一 Auth 的关系（docs/Auth.md Phase 3）
 * | 推荐 API | 说明 |
 * |----------|------|
 * | assertAuthorizedForUser | 身份：JWT AuthUser 或服务间 API Key |
 * | assertCanResumeForUser | resume：身份 + assignee=user.userId（admin 可跨） |
 * | assertCanListTasksForUser | 列表：非 admin 不可查他人 assignee |
 *
 * 角色只认 {@see AuthUser::roles}（JWT claim），**不再信任**客户端自报 `X-Workflow-Role`。
 *
 * ## 配置（Config/workflow.php → workflow.hitl）
 * | 配置项 | 说明 |
 * |--------|------|
 * | auth_enabled | false 时所有 assert* 直接放行（开发/单测） |
 * | api_key | 服务间旁路；Header X-Workflow-Api-Key 或 Body apiKey |
 * | allowed_roles | 与 AuthUser.roles 求交 |
 * | require_assignee_match | resume 时是否强制 user.userId = PauseNode.assignee |
 *
 * 控制器参考：Test\Module\Workflow\Controller\WorkflowController
 *
 * @see docs/Auth.md
 * @see WorkflowConfig::hitlAuthEnabled()
 */
final class WorkflowHitlAuth
{
    /** API Key 默认 Header 名（Body 字段 apiKey 为备选）。 */
    public const DEFAULT_API_KEY_HEADER = 'X-Workflow-Api-Key';

    /**
     * 角色 Header 名（历史兼容；auth_enabled 下已不再单独据此放行）。
     * 新代码请用 AuthUser::roles。
     */
    public const DEFAULT_ROLE_HEADER = 'X-Workflow-Role';

    /** 管理员角色：可跨 assignee resume / 查看全部 pause tasks。 */
    public const ADMIN_ROLE = 'admin';

    public function __construct(
        private readonly WorkflowConfig $config,
    ) {
    }

    /** 是否启用 HITL 鉴权（读取 workflow.hitl.auth_enabled / 环境变量）。 */
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
     * 第一层鉴权（ForUser）：调用方是否允许访问 HITL API。
     *
     * 规则（auth_enabled=true）：
     *   1. apiKeyHeader 与配置 api_key 一致（hash_equals）→ 通过（服务间）
     *   2. 否则必须提供 AuthUser，且 roles ∩ allowed_roles 非空（allowed_roles 空则放行有用户）
     *   3. 仅 Header role、无 JWT、无 Key → 403
     *
     * auth_enabled=false 时直接 return。
     *
     * @param AuthUser|null $user          FrameworkContext::user()，可为 null
     * @param string|null   $apiKeyHeader  X-Workflow-Api-Key 或 Body apiKey
     *
     * @throws WorkflowPermissionException
     */
    public function assertAuthorizedForUser(?AuthUser $user, ?string $apiKeyHeader): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $expectedKey = $this->config->hitlApiKey();
        if ($expectedKey !== '' && is_string($apiKeyHeader) && hash_equals($expectedKey, $apiKeyHeader)) {
            return;
        }

        if ($user === null) {
            throw new WorkflowPermissionException('Unauthorized workflow HITL request');
        }

        $allowedRoles = $this->config->hitlAllowedRoles();
        if ($allowedRoles === []) {
            return;
        }

        foreach ($user->roles as $role) {
            if (in_array($role, $allowedRoles, true)) {
                return;
            }
        }

        throw new WorkflowPermissionException('Unauthorized workflow HITL request');
    }

    /**
     * resume 前完整鉴权：身份 + assignee 归属。
     *
     * 顺序：
     *   1. assertAuthorizedForUser（此处不传 API Key；服务间 Key 由控制器另路径处理）
     *   2. require_assignee_match=false → 放行
     *   3. Run 非 WAITING → 跳过 assignee（Engine 会另行报错）
     *   4. user.isAdmin() → 可处理任意 assignee
     *   5. PauseNode.assignee 与 user.userId 精确匹配（**禁止** Body.actor 冒充）
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanResumeForUser(WorkflowRun $run, AuthUser $user): void
    {
        $this->assertAuthorizedForUser($user, null);

        if (!$this->config->hitlRequireAssigneeMatch()) {
            return;
        }

        if ($run->status !== RunStatus::WAITING) {
            return;
        }

        if ($user->isAdmin()) {
            return;
        }

        $taskAssignee = $this->resolveTaskAssignee($run);
        // PauseNode 未配置 assignee 时不做额外限制
        if ($taskAssignee === null) {
            return;
        }

        if ($user->userId !== $taskAssignee) {
            throw new WorkflowPermissionException(
                "Assignee mismatch: task is assigned to [{$taskAssignee}]",
            );
        }
    }

    /**
     * listPauseTasks 前鉴权：防止横向越权查看他人队列。
     *
     * - admin：不限制 filter
     * - 非 admin：若带了 assignee 过滤，必须等于 user.userId
     * - 未带 filter：由 {@see resolveListAssigneeFilterForUser()} 默认收窄为 user.userId
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanListTasksForUser(?string $filterAssignee, AuthUser $user): void
    {
        $this->assertAuthorizedForUser($user, null);

        if (!$this->isEnabled()) {
            return;
        }

        if ($user->isAdmin()) {
            return;
        }

        if ($filterAssignee === null || $filterAssignee === '') {
            return;
        }

        if ($user->userId !== $filterAssignee) {
            throw new WorkflowPermissionException('Cannot list tasks for another assignee');
        }
    }

    /**
     * @deprecated 使用 {@see assertAuthorizedForUser()}。
     *             auth_enabled 下：若已 setUser 则转 ForUser；否则 **仅** API Key，不再接受仅 Header role。
     *
     * @param string|null $role 已忽略（保留参数签名兼容旧调用）
     *
     * @throws WorkflowPermissionException
     */
    public function assertAuthorized(?string $apiKeyHeader, ?string $role): void
    {
        unset($role);

        if (!$this->isEnabled()) {
            return;
        }

        if (FrameworkContext::check()) {
            $this->assertAuthorizedForUser(FrameworkContext::user(), $apiKeyHeader);

            return;
        }

        $expectedKey = $this->config->hitlApiKey();
        if ($expectedKey !== '' && is_string($apiKeyHeader) && hash_equals($expectedKey, $apiKeyHeader)) {
            return;
        }

        throw new WorkflowPermissionException('Unauthorized workflow HITL request');
    }

    /**
     * @deprecated 使用 {@see assertCanResumeForUser()}。
     *             已 setUser → ForUser；auth 开启 → 仅 Key 身份后放行；auth 关闭 → 旧 actor 字符串比对（单测）。
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanResume(WorkflowRun $run, ?string $apiKeyHeader, ?string $actor, ?string $role): void
    {
        if (FrameworkContext::check()) {
            $this->assertCanResumeForUser($run, FrameworkContext::userOrFail());

            return;
        }

        if ($this->isEnabled()) {
            $this->assertAuthorized($apiKeyHeader, $role);

            return;
        }

        // auth_enabled=false：仅保留 assignee 匹配（开发/单测夹具）
        if (!$this->config->hitlRequireAssigneeMatch() || $run->status !== RunStatus::WAITING) {
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
     * @deprecated 使用 {@see assertCanListTasksForUser()}
     *
     * @throws WorkflowPermissionException
     */
    public function assertCanListTasks(?string $filterAssignee, ?string $apiKeyHeader, ?string $actor, ?string $role): void
    {
        unset($actor, $role);

        if (FrameworkContext::check()) {
            $this->assertCanListTasksForUser($filterAssignee, FrameworkContext::userOrFail());

            return;
        }

        if ($this->isEnabled()) {
            $this->assertAuthorized($apiKeyHeader, null);
        }
    }

    /**
     * 计算传给 Engine::listPauseTasks() 的 assignee 过滤值。
     *
     * 须在列表鉴权通过后调用：
     *   - 有 query assignee → 原样使用
     *   - auth 关闭或 role=admin → null（查全部）
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
     * ForUser 路径的列表过滤：admin 查全部，否则默认 actor = user.userId。
     */
    public function resolveListAssigneeFilterForUser(?string $queryAssignee, AuthUser $user): ?string
    {
        $role = $user->isAdmin() ? self::ADMIN_ROLE : null;

        return $this->resolveListAssigneeFilter($queryAssignee, $user->userId, $role);
    }

    /**
     * 从 PauseNode 执行输出中提取任务处理人。
     * PauseNode 成功时写入 nodeOutputs[pauseNodeId].assignee。
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
