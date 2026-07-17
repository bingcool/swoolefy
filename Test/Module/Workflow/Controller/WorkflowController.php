<?php

declare(strict_types=1);

namespace Test\Module\Workflow\Controller;

use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\AI\Stream\SseResponse;
use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunPresenter;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowHitlAuth;
use Test\Module\Workflow\WorkflowService;

/**
 * Workflow 通用 HTTP API —— 按 workflowId 启动 / 查询 / 恢复已注册工作流。
 *
 * 与 Order / Research / Outdoor / Rag 专用 Demo 的区别：
 *   - 目录 / describe：联邦 {@see WorkflowService::registry()}
 *   - 启动：{@see WorkflowService::engineFor()} → 拥有模块 Registry 绑定的 RunStore
 *   - status/resume/cancel：{@see WorkflowService::engineForRun()} 按 runId 路由到拥有方
 *   - 专用 Demo 可注入 mock，且必须用本模块 *WorkflowService（谁启动谁查询）
 *
 * 路由定义：`Test/Router/Module/Workflow.php`（前缀 `api`，默认端口 9501）。
 * 各方法 PHPDoc 中 curl 代码块无行首 `*`，便于直接复制执行。
 *
 * HITL（auth_enabled=true）鉴权（见 docs/Auth.md）：
 *   - 推荐：路由挂 AuthenticateMiddleware，用 `Authorization: Bearer <jwt>`；
 *     角色来自 JWT claims，admin 可跨 assignee。
 *   - 服务间：`X-Workflow-Api-Key`（Test 默认见 workflow.php hitl.api_key）。
 *   - **不再信任**客户端自报 `X-Workflow-Role`。
 *
 * @see \Test\Module\Workflow\README.md
 * @see docs/Auth.md
 */
final class WorkflowController extends BController
{
    /**
     * 列出已注册工作流目录。
     *
     * Route: GET /api/v1/workflow/list
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/list' \
       -H 'Accept: application/json'
     ```
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'workflows' => WorkflowService::catalog(),
            'count' => count(WorkflowService::registry()->ids()),
        ];
    }

    /**
     * 查看单个工作流的 DAG 详情（节点、固定边、条件边）。
     *
     * Route: GET /api/v1/workflow/describe
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/describe?workflowId=order_processing' \
       -H 'Accept: application/json'
     ```
     *
     * @return array<string, mixed>
     */
    public function describe(RequestInput $requestInput): array
    {
        $workflowId = (string) $requestInput->input('workflowId', '');
        if ($workflowId === '') {
            throw new SystemException('workflowId is required', 400);
        }

        try {
            return WorkflowService::describe($workflowId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }
    }

    /**
     * 启动工作流运行。
     *
     * Route: POST /api/v1/workflow/run
     *
     * 也支持顶层平铺 orderId / query 等字段（会合并进 input）。
     * stream=true 或 Accept: text/event-stream 时以 SSE 推送 run.start / complete。
     *
     ```bash
     # JSON：订单处理
     curl -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "workflowId": "order_processing",
         "input": {
           "orderId": "ORD-1",
           "amount": 99
         },
         "stream": false
       }'

     # 顶层平铺快捷字段
     curl -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
       -H 'Content-Type: application/json' \
       -d '{
         "workflowId": "order_processing",
         "orderId": "ORD-2",
         "amount": 88
       }'

     # SSE 流式启动
     curl -N -X POST 'http://127.0.0.1:9501/api/v1/workflow/run' \
       -H 'Content-Type: application/json' \
       -H 'Accept: text/event-stream' \
       -d '{
         "workflowId": "order_processing",
         "input": {"orderId": "ORD-SSE-1", "amount": 50},
         "stream": true
       }'
     ```
     *
     * @return array<string, mixed>|null stream 模式返回 null（响应已由 SSE 写出）
     */
    public function run(RequestInput $requestInput, ResponseOutput $responseOutput): ?array
    {
        $workflowId = (string) $requestInput->input('workflowId', '');
        if ($workflowId === '') {
            throw new SystemException('workflowId is required', 400);
        }

        $input = $this->normalizeInput($requestInput);
        $stream = $this->wantsStream($requestInput);

        try {
            $registry = WorkflowService::registry();
            if (!$registry->has($workflowId)) {
                throw new SystemException(
                    "Workflow {$workflowId} is not registered. GET /api/v1/workflow/list for available ids.",
                    404,
                );
            }

            // 编译与 Runtime 均走拥有方 Registry，保证 RunStore ↔ Registry 一致
            $ownerRegistry = WorkflowService::registryFor($workflowId);
            $compiled = $ownerRegistry->compiled($workflowId);
            $engine = WorkflowService::engineFor($workflowId, events: new StreamWorkflowEventDispatcher());

            // SSE：边启动边推送事件（演示用；生产可接 StreamWorkflowEventDispatcher 实时边事件）
            if ($stream) {
                $this->runWithStream($responseOutput, $engine, $compiled, $input);

                return null;
            }

            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 查询 Run 状态。
     *
     * Route: GET /api/v1/workflow/run/status
     *
     * 受 HITL 鉴权保护；默认返回脱敏摘要，admin + detail=true 才返回完整 state。
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/run/status?runId=run_xxxx' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <jwt>'

     # admin 拉取完整 state
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/run/status?runId=run_xxxx&detail=true' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <admin-jwt>'
     ```
     *
     * @return array<string, mixed>
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $run = WorkflowService::engineForRun($runId)->getRun($runId);

            return $this->formatRun($run, $this->wantsRunDetail($requestInput, $hitlAuth));
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }
    }

    /**
     * HITL 恢复：对 WAITING 状态的 Run 提交 feedback 并继续执行。
     *
     * Route: POST /api/v1/workflow/run/resume
     *
     * 鉴权：Bearer JWT（AuthUser）或服务间 X-Workflow-Api-Key；不再信任 X-Workflow-Role。
     * require_assignee_match 时以 JWT AuthUser.userId 匹配 PauseNode assignee（admin 可跨）。
     * 典型场景：contract_review 的 legal_review 暂停节点。
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/workflow/run/resume' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <jwt>' \
       -d '{
         "runId": "run_xxxx",
         "feedback": {
           "approved": true,
           "reason": "ok"
         }
       }'
     ```
     *
     * @return array<string, mixed>
     */
    public function resume(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        $feedback = $requestInput->input('feedback', []);
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }
        if (!is_array($feedback)) {
            throw new SystemException('feedback must be an object', 400);
        }

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $engine = WorkflowService::engineForRun($runId, events: new StreamWorkflowEventDispatcher());
            $run = $engine->getRun($runId);
            $this->assertHitlResume($hitlAuth, $requestInput, $run);
            $engine->resume($runId, $feedback);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 取消 Run（标记 CANCELLED，不触发 Saga 补偿）。
     *
     * Route: POST /api/v1/workflow/run/cancel
     *
     * 受 HITL 鉴权保护（auth_enabled 时须 JWT 或 API Key）。
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/workflow/run/cancel' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <jwt>' \
       -d '{
         "runId": "run_xxxx"
       }'
     ```
     *
     * @return array<string, mixed>
     */
    public function cancel(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $engine = WorkflowService::engineForRun($runId);
            $engine->cancel($runId);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 列出 HITL 暂停任务（可选按 assignee 过滤）。
     *
     * Route: GET /api/v1/workflow/pause/tasks
     *
     * 受 HITL 鉴权保护；非 admin 只能列出本人（JWT AuthUser.userId）的任务。
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/pause/tasks?assignee=legal-team' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <jwt>'

     # admin 查看全部
     curl -X GET 'http://127.0.0.1:9501/api/v1/workflow/pause/tasks' \
       -H 'Accept: application/json' \
       -H 'Authorization: Bearer <admin-jwt>'
     ```
     *
     * @return array<string, mixed>
     */
    public function pauseTasks(RequestInput $requestInput): array
    {
        $assignee = $requestInput->input('assignee');
        $assignee = is_string($assignee) && $assignee !== '' ? $assignee : null;

        $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());

        try {
            $listAssignee = $this->assertHitlListTasks($hitlAuth, $requestInput, $assignee);
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        }

        // 聚合各拥有方 RunStore 上的 WAITING 任务（模块本地 + 枢纽）
        $tasks = [];
        foreach ([
            WorkflowService::engineFor('order_processing'),
            WorkflowService::engineFor('outdoor_cycling'),
            WorkflowService::engineFor('multi_agent_research'),
            WorkflowService::engineFor('rag_qa'),
            WorkflowService::engineFor('contract_review'),
            WorkflowService::engineFor('knowledge_qa'),
        ] as $engine) {
            foreach ($engine->listPauseTasks($listAssignee) as $task) {
                $tasks[] = $task;
            }
        }

        return [
            'tasks' => $tasks,
            'assignee' => $listAssignee,
        ];
    }

    /**
     * 对已存在 Run 以 SSE 推送当前状态（演示用，非历史事件回放）。
     *
     * Route: GET /api/v1/workflow/run/events
     *
     * 受 HITL 鉴权保护。
     *
     ```bash
     curl -N -X GET 'http://127.0.0.1:9501/api/v1/workflow/run/events?runId=run_xxxx' \
       -H 'Accept: text/event-stream' \
       -H 'Authorization: Bearer <jwt>'
     ```
     */
    #[StreamResponse]
    public function events(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $runId = (string) $requestInput->input('runId', '');
        $sink = SseResponse::open($responseOutput);

        try {
            if ($runId === '') {
                throw new SystemException('runId is required', 400);
            }

            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $run = WorkflowService::engineForRun($runId)->getRun($runId);
            $sink->publish('run.status', [
                'runId' => $runId,
                'status' => $run->status->value,
                'lastRoutedEdge' => $run->lastRoutedEdge,
                'currentNodeId' => $run->currentNodeId,
            ]);
            $sink->publish('complete', $this->formatRun($run, false));
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /**
     * SSE 模式启动：推送 run.start → 执行 → complete。
     *
     * @param array<string, mixed> $input
     */
    private function runWithStream(
        ResponseOutput $responseOutput,
        WorkflowEngine $engine,
        CompiledWorkflow $compiled,
        array $input,
    ): void {
        $sink = SseResponse::open($responseOutput);

        try {
            $sink->publish('run.start', [
                'workflowId' => $compiled->workflowId(),
                'version' => $compiled->version(),
            ]);
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
            $sink->publish('complete', $this->formatRun($run));
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /**
     * 统一 Run 响应结构。
     *
     * @return array<string, mixed>
     */
    private function formatRun(WorkflowRun $run, bool $includeDetails = true): array
    {
        return WorkflowRunPresenter::toArray($run, $includeDetails);
    }

    /**
     * 规范化启动入参。
     *
     * 优先使用 Body.input 对象；同时允许顶层平铺常用字段，便于 curl 简写：
     *   { "workflowId": "mcp_research", "query": "urgent fix" }
     * 等价于
     *   { "workflowId": "mcp_research", "input": { "query": "urgent fix" } }
     *
     * @return array<string, mixed>
     */
    private function normalizeInput(RequestInput $requestInput): array
    {
        $input = $requestInput->input('input', []);
        if (!is_array($input)) {
            $input = [];
        }

        // 顶层快捷字段：未在 input 中出现时才合并，避免覆盖显式 input
        foreach ([
            'userId',
            'sessionId',
            'orderId',
            'amount',
            'currency',
            'query',
            'question',
            'contractBrief',
            'items',
        ] as $key) {
            $value = $requestInput->input($key);
            if ($value !== null && $value !== '' && !array_key_exists($key, $input)) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * 是否以 SSE 流式返回：Body.stream=true 或 Accept 含 text/event-stream。
     */
    private function wantsStream(RequestInput $requestInput): bool
    {
        if (filter_var($requestInput->input('stream', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $accept = strtolower((string) $requestInput->getHeaderParams('accept', ''));

        return str_contains($accept, 'text/event-stream');
    }

    /**
     * status 调试详情开关：仅已验票 AuthUser 且 isAdmin，并显式 detail/debug=true。
     * 不再看 X-Workflow-Role（可伪造）。
     */
    private function wantsRunDetail(RequestInput $requestInput, WorkflowHitlAuth $hitlAuth): bool
    {
        unset($hitlAuth);
        $detail = filter_var(
            $requestInput->input('detail', $requestInput->input('debug', false)),
            FILTER_VALIDATE_BOOLEAN,
        );
        if (!$detail) {
            return false;
        }

        return FrameworkContext::user()?->isAdmin() === true;
    }

    /**
     * HITL 第一层：JWT（AuthenticateMiddleware setUser）或服务间 API Key。
     * auth_enabled=false 时 ForUser 内部直接放行。
     *
     * @throws WorkflowPermissionException
     */
    private function assertHitlAuthorized(WorkflowHitlAuth $hitlAuth, RequestInput $requestInput): void
    {
        $hitlAuth->assertAuthorizedForUser(
            FrameworkContext::user(),
            $this->hitlApiKey($requestInput),
        );
    }

    /**
     * resume 鉴权：
     * - 有 AuthUser → assertCanResumeForUser（assignee = user.userId，禁止 Body.actor 冒充）
     * - 仅 API Key → 系统旁路，不做 assignee 限制
     *
     * @throws WorkflowPermissionException
     */
    private function assertHitlResume(
        WorkflowHitlAuth $hitlAuth,
        RequestInput $requestInput,
        \Swoolefy\Support\Workflow\Engine\WorkflowRun $run,
    ): void {
        $user = FrameworkContext::user();
        $apiKey = $this->hitlApiKey($requestInput);
        if ($user instanceof AuthUser) {
            $hitlAuth->assertCanResumeForUser($run, $user);

            return;
        }

        $hitlAuth->assertAuthorizedForUser(null, $apiKey);
    }

    /**
     * listPauseTasks 鉴权并返回引擎侧 assignee 过滤值。
     *
     * - AuthUser：非 admin 默认只查本人；query assignee 不可指向他人
     * - 仅 API Key：信任调用方传入的 filter（服务间）
     *
     * @return string|null null = 不按 assignee 过滤（全部）
     *
     * @throws WorkflowPermissionException
     */
    private function assertHitlListTasks(
        WorkflowHitlAuth $hitlAuth,
        RequestInput $requestInput,
        ?string $filterAssignee,
    ): ?string {
        $user = FrameworkContext::user();
        $apiKey = $this->hitlApiKey($requestInput);
        if ($user instanceof AuthUser) {
            $hitlAuth->assertCanListTasksForUser($filterAssignee, $user);

            return $hitlAuth->resolveListAssigneeFilterForUser($filterAssignee, $user);
        }

        $hitlAuth->assertAuthorizedForUser(null, $apiKey);

        return $filterAssignee;
    }

    /**
     * 解析 HITL 服务间 API Key。
     * 优先 Header {@see WorkflowHitlAuth::DEFAULT_API_KEY_HEADER}，其次 Body `apiKey`。
     */
    private function hitlApiKey(RequestInput $requestInput): ?string
    {
        $header = (string) $requestInput->getHeaderParams(WorkflowHitlAuth::DEFAULT_API_KEY_HEADER, '');
        if ($header !== '') {
            return $header;
        }

        $body = $requestInput->input('apiKey');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }
}
