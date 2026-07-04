<?php

namespace Test\Router;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Test 应用公共 API 路由表。
 *
 * 说明：
 *   - 本文件在路由加载阶段执行，通过 Route::get/post/match/group 注册 URI → Controller。
 *   - 带 prefix=api 的分组路由，实际访问路径为 /api/...（例如 /api/v1/workflow/run）。
 *   - dispatch_route：[控制器类名, 方法名]。
 *   - beforeHandle / afterHandle：请求前后钩子（可多个，后缀数字区分）。
 *
 * 模块文档：
 *   - Workflow：Test/Module/Workflow/README.md
 *   - Order：   Test/Module/Order/README.md
 *   - Research：Test/Module/Research/README.md
 */

// ---------------------------------------------------------------------------
// 根路径示例：演示 before/after 钩子与协程 Context（无 /api 前缀）
// ---------------------------------------------------------------------------
Route::get('/index/index', [
    'beforeHandle' => function(RequestInput $requestInput) {
        Context::set('name', 'bingcool');
        $name = $requestInput->input('name');
    },

    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

    'afterHandle' => function(RequestInput $requestInput) {

    },
    'afterHandle1' => function(RequestInput $requestInput) {

    },
])->enableCacheRouteMeta(false);

// ---------------------------------------------------------------------------
// /api 分组：统一前缀 + GroupTestMiddleware
// 以下路径均需加前缀，例如 Route::get('/v1/...') → GET /api/v1/...
// ---------------------------------------------------------------------------
Route::group([
    // 路由前缀（最终 URI = /{prefix}/{path}）
    'prefix' => 'api',
    // 分组级中间件（对该 group 内所有路由生效）
    'middleware' => [
        GroupTestMiddleware::class
    ]
], function () {

    // -----------------------------------------------------------------------
    // 基础 / 框架能力演示（Index、Token、缓存、队列、SSE、上传下载等）
    // -----------------------------------------------------------------------

    // GET /api/ — 首页，演示 before/after 钩子链
    Route::get('/', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $requestInput->setValue('name','bingcool');
        },

        'beforeHandle1' => function(RequestInput $requestInput) {
            $name = $requestInput->getValue('name');
            var_dump($name);
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
            var_dump("after:".$requestInput->getValue('name'));
        },

        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle1');
        },
    ]);

    // GET /api/index/testLog — 日志组件演示
    Route::get('/index/testLog', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testLog'],
    ]);

    // GET /api/token/jwt — JWT 签发 / 校验演示
    Route::get('/token/jwt', [
        'dispatch_route' => [\Test\Controller\TokenController::class, 'jwt'],
    ]);

    // GET /api/getUuid — UUID 生成演示
    Route::get('/getUuid', [
        'dispatch_route' => [\Test\Controller\UuidController::class, 'getUuid'],
    ]);

    // GET /api/lock-test1 — 分布式锁演示
    Route::get('/lock-test1', [
        'dispatch_route' => [\Test\Controller\LockController::class, 'locktest1'],
    ]);

    // GET /api/rate-test1 — 限流演示
    Route::get('/rate-test1', [
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'ratetest1'],
    ]);

    // GET /api/validate-test1 — 请求参数校验演示
    Route::get('/validate-test1', [
        'dispatch_route' => [\Test\Controller\ValidateController::class, 'test1'],
    ]);

    // GET /api/ws — WebSocket 相关演示入口
    Route::get('/ws', [
        'dispatch_route' => [\Test\Controller\WsController::class, 'test1'],
    ]);

    // GET /api/send-task-worker — 投递 Task Worker 演示
    Route::get('/send-task-worker', [
        'dispatch_route' => [\Test\Controller\ProcessController::class, 'sendTaskWorker'],
    ]);

    // GET /api/cache/test — 缓存读写演示
    Route::get('/cache/test', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test'],
    ]);

    // GET|POST /api/cache/test1 — 缓存另一组用例
    Route::match(['GET','POST'],'/cache/test1', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
    ]);

    // GET|POST /api/queue/push — 消息队列入队演示
    Route::match(['GET','POST'],'/queue/push', [
        'dispatch_route' => [\Test\Controller\QueueController::class, 'push'],
    ]);

    // GET|POST /api/captcha/image — 验证码图片演示
    Route::match(['GET','POST'],'/captcha/image', [
        'dispatch_route' => [\Test\Controller\CaptchaController::class, 'test'],
    ]);

    // GET /api/bank/addBank — 对象容器 / 业务对象演示
    Route::match(['GET'],'/bank/addBank', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'addBank'],
    ]);

    // GET /api/redis/test — Redis 客户端演示
    Route::match(['GET'],'/redis/test', [
        'dispatch_route' => [\Test\Controller\RedisController::class, 'testRedis'],
    ]);

    // GET /api/transaction/test — 数据库事务演示
    Route::match(['GET'],'/transaction/test', [
        'dispatch_route' => [\Test\Controller\TransactionController::class, 'test'],
    ]);

    // GET /api/sse/stream — SSE 流式推送演示
    Route::get('/sse/stream', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'stream'],
    ]);

    // GET /api/sse/tick — SSE 定时 tick 演示
    Route::get('/sse/tick', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'tick'],
    ]);

    // GET /api/chunked/ndjson — Chunked NDJSON 响应演示
    Route::get('/chunked/ndjson', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'ndjson'],
    ]);

    // GET /api/chunked/text — Chunked 文本响应演示
    Route::get('/chunked/text', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'text'],
    ]);

    // GET /api/download/file — 附件下载（attachment）
    Route::get('/download/file', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'file'],
    ]);

    // GET /api/download/inline — 浏览器内联预览下载
    Route::get('/download/inline', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'inline'],
    ]);

    // POST /api/upload/single — 单文件上传
    Route::post('/upload/single', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'single'],
    ]);

    // POST /api/upload/multiple — 多文件上传
    Route::post('/upload/multiple', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'multiple'],
    ]);

    // -----------------------------------------------------------------------
    // Research 模块 — 研究工作流演示
    // 文档：Test/Module/Research/README.md
    // -----------------------------------------------------------------------

    // POST /api/v1/research/workflow/multi-agent
    // Body: { "query": "...", "useMock": true }
    // 多 Agent 并行研究（coding + finance）→ summary
    Route::post('/v1/research/workflow/multi-agent', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'multiAgent'],
    ]);

    // POST /api/v1/research/workflow/mcp
    // Body: { "query": "urgent ...", "mockSummary": {...}, "useRealResearchAgent": false }
    // MCP 研究 → 摘要 → urgent? notify : archive
    Route::post('/v1/research/workflow/mcp', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'mcp'],
    ]);

    // GET /api/v1/research/workflow/status?runId=
    // 查询 Research 工作流 Run 状态
    Route::get('/v1/research/workflow/status', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'status'],
    ]);

    // -----------------------------------------------------------------------
    // Order 模块 — 订单工作流演示
    // 文档：Test/Module/Order/README.md
    // -----------------------------------------------------------------------

    // POST /api/v1/order/workflow/process
    // Body: { "orderId": "...", "amount": 199, "mockDecision": {approved, confidence, reason} }
    // 订单处理：validate → AI 风控 → payment / manual_review / reject → complete
    Route::post('/v1/order/workflow/process', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'process'],
    ]);

    // POST /api/v1/order/workflow/saga
    // Body: { "orderId": "...", "amount": 50 }
    // Saga：支付后通知失败 → 逆序退款 + 释库存
    Route::post('/v1/order/workflow/saga', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'saga'],
    ]);

    // GET /api/v1/order/workflow/status?runId=
    // 查询订单工作流 Run 状态
    Route::get('/v1/order/workflow/status', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'status'],
    ]);

    // POST /api/v1/order/workflow/resume
    // Body: { "runId": "...", "feedback": { "approved": true, "reason": "..." } }
    // HITL 恢复（manual_review pauseForHuman=true 时）
    Route::post('/v1/order/workflow/resume', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'resume'],
    ]);

    // -----------------------------------------------------------------------
    // Workflow 模块 — 通用工作流 API（按 workflowId 调度已注册定义）
    // 文档：Test/Module/Workflow/README.md
    // 已注册：order_processing / order_saga / multi_agent_research /
    //         mcp_research / contract_review / knowledge_qa
    // -----------------------------------------------------------------------

    // GET /api/v1/workflow/list — 列出已注册工作流及 demoInput
    Route::get('/v1/workflow/list', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'list'],
    ]);

    // GET /api/v1/workflow/describe?workflowId=order_processing
    // 查看节点、固定边、条件边表达式
    Route::get('/v1/workflow/describe', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'describe'],
    ]);

    // POST /api/v1/workflow/run
    // Body: { "workflowId": "...", "input": {...}, "stream": false }
    // 也支持顶层平铺 orderId / query / contractBrief 等字段
    Route::post('/v1/workflow/run', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'run'],
    ]);

    // GET /api/v1/workflow/run/status?runId= — 查询 Run 状态
    Route::get('/v1/workflow/run/status', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'status'],
    ]);

    // POST /api/v1/workflow/run/resume
    // Body: { "runId": "...", "feedback": {...} }
    // HITL 恢复（如 contract_review 的 legal_review）
    Route::post('/v1/workflow/run/resume', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'resume'],
    ]);

    // POST /api/v1/workflow/run/cancel
    // Body: { "runId": "..." } — 标记 Run 为 CANCELLED（不触发 Saga 补偿）
    Route::post('/v1/workflow/run/cancel', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'cancel'],
    ]);

    // GET /api/v1/workflow/pause/tasks?assignee=legal-team
    // 列出 HITL 暂停任务（可按处理人过滤）
    Route::get('/v1/workflow/pause/tasks', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'pauseTasks'],
    ]);

    // GET /api/v1/workflow/run/events?runId=
    // SSE：推送已有 Run 的当前状态（演示用）
    Route::get('/v1/workflow/run/events', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'events'],
    ]);

    // -----------------------------------------------------------------------
    // MCP 模块 — MCP Server / Tools 探查
    // -----------------------------------------------------------------------

    // GET /api/v1/mcp/servers — 列出已配置的 MCP Server
    Route::get('/v1/mcp/servers', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'servers'],
    ]);

    // GET /api/v1/mcp/servers/{id}/tools — 列出指定 Server 的 Tools
    Route::get('/v1/mcp/servers/{id}/tools', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'tools'],
    ]);

    // -----------------------------------------------------------------------
    // Agent 模块 — Neuron Agent 能力演示
    // -----------------------------------------------------------------------

    // POST /api/v1/agent/chat — 基础对话
    Route::post('/v1/agent/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chat'],
    ]);

    // POST /api/v1/agent/chat1 — 对话变体示例
    Route::post('/v1/agent/chat1', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chat1'],
    ]);

    // POST /api/v1/agent/chat-thinking — 带 thinking / reasoning 的对话
    Route::post('/v1/agent/chat-thinking', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chatThinking'],
    ]);

    // POST /api/v1/agent/chat-persist — 持久化会话（SQL ChatHistory）
    Route::post('/v1/agent/chat-persist', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chatPersist'],
    ]);

    // POST /api/v1/agent/weather — 结构化输出（WeatherDto）
    Route::post('/v1/agent/weather', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentStructuredController::class, 'weather'],
    ]);

    // POST /api/v1/agent/polish/recommendation — 履历润色 → 推荐信结构化输出
    Route::post('/v1/agent/polish/recommendation', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentPolishController::class, 'recommendation'],
    ]);

    // POST /api/v1/agent/vision/chat — 多模态（图片 + 文本）
    Route::post('/v1/agent/vision/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentVisionController::class, 'chat'],
    ]);

    // POST /api/v1/agent/stream/chat — SSE 流式对话
    Route::post('/v1/agent/stream/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentStreamController::class, 'chat'],
    ]);

    // POST /api/v1/agent/tool/weather — Tool Calling（天气工具）
    Route::post('/v1/agent/tool/weather', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentToolController::class, 'weather'],
    ]);

    // POST /api/v1/agent/tool/weather/stream — Tool Calling + SSE 流式
    Route::post('/v1/agent/tool/weather/stream', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentToolController::class, 'weatherStream'],
    ]);
});

// ---------------------------------------------------------------------------
// 分组外路由（无 /api 前缀）：GET /cache/test1
// ---------------------------------------------------------------------------
Route::match(['GET'],'/cache/test1', [
    'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
]);
