<?php

declare(strict_types=1);

namespace Test\Module\Workflow\Controller;

use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\AI\Stream\SseResponse;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Workflow\WorkflowService;

/**
 * Workflow HTTP API（Phase 2）。
 *
 * POST /api/v1/workflow/run
 * GET  /api/v1/workflow/run/status?runId=
 * POST /api/v1/workflow/run/resume
 * GET  /api/v1/workflow/run/events?runId=  (SSE)
 */
final class WorkflowController extends BController
{
    /**
     * 启动工作流运行。
     *
     * Body: { "workflowId": "order_processing", "input": {...}, "stream": false }
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
            $compiled = $registry->compiled($workflowId);
            $engine = WorkflowBootstrap::engine(events: new StreamWorkflowEventDispatcher());

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

    /** GET /api/v1/workflow/run/status?runId= */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = WorkflowBootstrap::engine()->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }
    }

    /** POST /api/v1/workflow/run/resume — Body: { "runId": "...", "feedback": {...} } */
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
            $engine = WorkflowBootstrap::engine(events: new StreamWorkflowEventDispatcher());
            $engine->resume($runId, $feedback);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /** GET /api/v1/workflow/pause/tasks?assignee= */
    public function pauseTasks(RequestInput $requestInput): array
    {
        $assignee = $requestInput->input('assignee');
        $assignee = is_string($assignee) ? $assignee : null;

        $engine = WorkflowBootstrap::engine();

        return [
            'tasks' => $engine->listPauseTasks($assignee),
        ];
    }

    /** GET /api/v1/workflow/run/events?runId= — 对已存在 Run 重放边路由事件（演示 SSE）。 */
    #[StreamResponse]
    public function events(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $runId = (string) $requestInput->input('runId', '');
        $sink = SseResponse::open($responseOutput);

        try {
            if ($runId === '') {
                throw new SystemException('runId is required', 400);
            }

            $run = WorkflowBootstrap::engine()->getRun($runId);
            $sink->publish('run.status', [
                'runId' => $runId,
                'status' => $run->status->value,
                'lastRoutedEdge' => $run->lastRoutedEdge,
            ]);
            $sink->publish('complete', $this->formatRun($run));
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /** @param array<string, mixed> $input */
    private function runWithStream(
        ResponseOutput $responseOutput,
        \Swoolefy\Support\Workflow\Engine\WorkflowEngine $engine,
        \Swoolefy\Support\Workflow\Definition\CompiledWorkflow $compiled,
        array $input,
    ): void {
        $sink = SseResponse::open($responseOutput);

        try {
            $sink->publish('run.start', ['workflowId' => $compiled->workflowId()]);
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
            $sink->publish('complete', $this->formatRun($run));
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /** @return array<string, mixed> */
    private function formatRun(WorkflowRun $run): array
    {
        return [
            'runId' => $run->runId,
            'workflowId' => $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'lastRoutedEdge' => $run->lastRoutedEdge,
            'currentNodeId' => $run->currentNodeId,
            'pauseNodeId' => $run->pauseNodeId,
            'error' => $run->error,
            'data' => $run->state->data,
            'nodeOutputs' => $run->state->nodeOutputs,
            'agentOutputs' => $run->state->agentOutputs,
            'waiting' => $run->status === RunStatus::WAITING,
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeInput(RequestInput $requestInput): array
    {
        $input = $requestInput->input('input', []);
        if (!is_array($input)) {
            $input = [];
        }

        foreach (['userId', 'sessionId', 'orderId', 'query'] as $key) {
            $value = $requestInput->input($key);
            if ($value !== null && !array_key_exists($key, $input)) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    private function wantsStream(RequestInput $requestInput): bool
    {
        if (filter_var($requestInput->input('stream', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $accept = strtolower((string) $requestInput->getHeaderParams('accept', ''));

        return str_contains($accept, 'text/event-stream');
    }
}
