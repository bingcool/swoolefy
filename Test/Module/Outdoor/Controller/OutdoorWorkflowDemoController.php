<?php

declare(strict_types=1);

namespace Test\Module\Outdoor\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Outdoor\Workflow\OutdoorCyclingWorkflow;

/**
 * 户外骑行多 Agent 并行工作流演示（本模块独立 Registry / Engine）。
 *
 * POST /api/v1/outdoor/workflow/cycling
 *   AgentA 天气 + AgentB 路线 + AgentC 备车（并行）→ 天气好则骑自行车出发。
 *   Body:
 *   {
 *     "destination": "深圳湾公园",
 *     "weatherHint": "sunny",   // mock 下：sunny→出发；rainy→留家
 *     "useMock": true
 *   }
 *
 * GET /api/v1/outdoor/workflow/status?runId=
 *
 * curl 示例（好天气出发）：
 *   curl -X POST "http://localhost:9501/api/v1/outdoor/workflow/cycling" \
 *     -H "Content-Type: application/json" \
 *     -d '{"destination":"深圳湾公园","weatherHint":"sunny","useMock":true}'
 *
 * curl 示例（雨天取消）：
 *   curl -X POST "http://localhost:9501/api/v1/outdoor/workflow/cycling" \
 *     -H "Content-Type: application/json" \
 *     -d '{"destination":"深圳湾公园","weatherHint":"rainy","useMock":true}'
 */
final class OutdoorWorkflowDemoController extends BController
{
    /**
     * 三 Agent 并行准备 → 按天气决定是否骑行出发。
     *
     * POST /api/v1/outdoor/workflow/cycling
     */
    public function cycling(RequestInput $requestInput): array
    {
        $destination = trim((string) $requestInput->input('destination', '深圳湾公园'));
        if ($destination === '') {
            throw new SystemException('destination is required', 400);
        }

        $weatherHint = trim((string) $requestInput->input('weatherHint', 'sunny'));
        $useMock = $this->boolInput($requestInput, 'useMock', default: true);

        $input = [
            'destination' => $destination,
            'weatherHint' => $weatherHint !== '' ? $weatherHint : 'sunny',
            'userId' => (string) ($requestInput->input('userId') ?: 'demo-user'),
            'sessionId' => (string) ($requestInput->input('sessionId')
                ?: ('sess-outdoor-' . substr(md5($destination), 0, 8))),
        ];

        $definition = OutdoorCyclingWorkflow::definition(
            OutdoorWorkflowService::agentScheduler(),
            useMockAgents: $useMock,
        );

        return $this->startAndFormat($definition, $input);
    }

    /**
     * GET /api/v1/outdoor/workflow/status?runId=
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = OutdoorWorkflowService::engine()->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }

        return $this->formatRun($run);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function startAndFormat(WorkflowDefinition $definition, array $input): array
    {
        try {
            $compiled = WorkflowComponentFactory::compiler()->compile($definition);
            $engine = OutdoorWorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return $this->formatRun($run, $definition->id());
    }

    /** @return array<string, mixed> */
    private function formatRun(WorkflowRun $run, ?string $workflowId = null): array
    {
        return [
            'runId' => $run->runId,
            'workflowId' => $workflowId ?? $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            'destination' => $run->state->get('destination'),
            'weatherHint' => $run->state->get('weatherHint'),
            'weatherGood' => $run->state->get('weatherGood'),
            'decision' => $run->state->get('decision'),
            'message' => $run->state->get('message'),
            'plan' => $run->state->get('plan'),
            'trip' => $run->state->get('trip'),
            'agentOutputs' => $run->state->agentOutputs,
            'error' => $run->error,
            'data' => $run->state->data,
        ];
    }

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
}
