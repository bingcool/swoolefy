<?php

declare(strict_types=1);

namespace Test\Module\Research\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Test\Module\Research\Workflow\McpResearchWorkflow;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * Research 工作流演示控制器 —— 展示 multi_agent_research / mcp_research 用法。
 *
 * 路由前缀（见 Test/Router/Common/Api.php）：
 *
 * POST /api/v1/research/workflow/multi-agent
 *   多 Agent 并行研究（coding + finance）→ summary。
 *   Body:
 *   {
 *     "query": "Analyze swoolefy workflow design",
 *     "useMock": true          // 可选，默认 true：不调 LLM，返回确定性 mock
 *   }
 *
 * POST /api/v1/research/workflow/mcp
 *   MCP 研究 → 摘要 → urgent? notify : archive。
 *   Body:
 *   {
 *     "query": "urgent security patch review",
 *     "mockSummary": {         // 可选，强制摘要字段与分支
 *       "urgent": true,
 *       "summary": "...",
 *       "source": "demo"
 *     },
 *     "useRealResearchAgent": false  // 可选，true 时 research 走真实 Agent
 *   }
 *
 * GET /api/v1/research/workflow/status?runId=
 *   查询 Run 状态与业务字段。
 *
 * @see\Test\Module\Research\README.md
 */
final class ResearchWorkflowDemoController extends BController
{
    /**
     * 演示：多 Agent 并行研究。
     *
     * 流程：parallel_research（coding + finance 并行）→ summary。
     * 默认 useMock=true，无需 OPENAI_API_KEY 即可跑通。
     *
     * POST /api/v1/research/workflow/multi-agent
     */
    public function multiAgent(RequestInput $requestInput): array
    {
        $input = $this->normalizeResearchInput($requestInput);
        // useMock 缺省为 true：演示环境优先确定性结果；传 false 走真实/Fake Agent。
        $useMock = $this->boolInput($requestInput, 'useMock', default: true);

        $definition = MultiAgentResearchWorkflow::definition(
            WorkflowService::agentScheduler(),
            useMockAgents: $useMock,
        );

        return $this->startAndFormat($definition, $input);
    }

    /**
     * 演示：MCP 研究 + 紧急度分支。
     *
     * 流程：research → summarize → notify（urgent）或 archive（非 urgent）。
     * - query 含 "urgent"（忽略大小写）→ notify
     * - 或传 mockSummary.urgent 强制分支
     *
     * POST /api/v1/research/workflow/mcp
     */
    public function mcp(RequestInput $requestInput): array
    {
        $input = $this->normalizeResearchInput($requestInput);
        $mockSummary = $requestInput->input('mockSummary');
        $mockSummary = is_array($mockSummary) ? $mockSummary : null;
        $useRealResearchAgent = $this->boolInput($requestInput, 'useRealResearchAgent', default: false);

        $definition = McpResearchWorkflow::definition(
            WorkflowService::neuronFactory(),
            mockSummary: $mockSummary,
            useRealResearchAgent: $useRealResearchAgent,
        );

        return $this->startAndFormat($definition, $input);
    }

    /**
     * 查询 Run 状态。
     *
     * GET /api/v1/research/workflow/status?runId=
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = WorkflowService::engine()->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }

        return $this->formatRun($run);
    }

    /**
     * compile + start，并格式化响应。
     *
     * 演示控制器直接 compile，避免与全局 registry 缓存的旧版本定义冲突。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function startAndFormat(WorkflowDefinition $definition, array $input): array
    {
        try {
            $compiled = WorkflowComponentFactory::compiler()->compile($definition);
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return $this->formatRun($run, $definition->id());
    }

    /**
     * 规范化研究入参：必填 query，可选 sessionId / userId。
     *
     * @return array<string, mixed>
     */
    private function normalizeResearchInput(RequestInput $requestInput): array
    {
        $query = $requestInput->input('query');
        if ($query === null || $query === '') {
            throw new SystemException('query is required', 400);
        }

        return [
            'query' => (string) $query,
            'userId' => (string) ($requestInput->input('userId') ?: 'demo-user'),
            'sessionId' => (string) ($requestInput->input('sessionId') ?: ('sess-research-' . substr(md5((string) $query), 0, 8))),
        ];
    }

    /**
     * 解析布尔入参（兼容 true/false、1/0、"true"/"false"）。
     */
    private function boolInput(RequestInput $requestInput, string $key, bool $default): bool
    {
        $value = $requestInput->input($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    /**
     * 统一响应结构，突出 Research 相关字段。
     *
     * @return array<string, mixed>
     */
    private function formatRun(WorkflowRun $run, ?string $workflowId = null): array
    {
        $summary = $run->state->get('summary');
        // agentOutputs 是 WorkflowState 的独立属性，不在 data 字典里。
        $agentOutputs = $run->state->agentOutputs;

        return [
            'runId' => $run->runId,
            'workflowId' => $workflowId ?? $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            // 研究主题（入参）
            'query' => $run->state->get('query'),
            // multi_agent_research：各 Agent 并行输出
            'agentOutputs' => $agentOutputs,
            // mcp_research：结构化摘要；multi_agent 的 summary 也在 data.summary
            'summary' => $summary,
            // mcp_research 分支结果
            'notified' => $run->state->get('notified'),
            'archived' => $run->state->get('archived'),
            // research stub / Agent 产出
            'content' => $run->state->get('content'),
            'mcpToolsUsed' => $run->state->get('mcpToolsUsed'),
            'error' => $run->error,
            'data' => $run->state->data,
        ];
    }
}
