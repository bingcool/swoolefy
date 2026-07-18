<?php

declare(strict_types=1);

namespace PhpUintTest\Http;

/**
 * 统一 Workflow API 黄金路径：list / run / status 缺参（contract_review，无 LLM）。
 *
 * 跨请求 status/resume 需要共享 RunStore。Test 默认 MEMORY + worker_num=4 时，
 * 第二次请求常打到其他 Worker → 「Workflow run not found」并打 ERROR 日志。
 * 该用例仅在显式声明共享 Store 时运行（见 testResume…）。
 */
final class WorkflowHttpTest extends HttpIntegrationTestCase
{
    public function testListWorkflowsIncludesOutdoorCycling(): void
    {
        $res = $this->getJson('/api/v1/workflow/list');
        $this->assertSame(200, $res['status']);
        $data = $this->responseData($res);

        $this->assertGreaterThanOrEqual(1, (int) ($data['count'] ?? 0));
        $this->assertIsArray($data['workflows'] ?? null);

        $ids = [];
        foreach ($data['workflows'] as $row) {
            if (is_array($row) && isset($row['workflowId'])) {
                $ids[] = (string) $row['workflowId'];
            }
        }
        $this->assertContains('outdoor_cycling', $ids);
        $this->assertContains('contract_review', $ids);
    }

    public function testRunContractReviewReturnsWaiting(): void
    {
        $start = $this->postJson('/api/v1/workflow/run', [
            'workflowId' => 'contract_review',
            'input' => [
                'contractBrief' => 'Http golden path SaaS agreement',
            ],
        ]);
        $this->assertSame(200, $start['status']);
        $started = $this->responseData($start);
        $this->assertNotSame('', (string) ($started['runId'] ?? ''));
        $this->assertSame('contract_review', $started['workflowId'] ?? null);
        $this->assertSame('waiting', $started['status'] ?? null);
    }

    public function testStatusMissingRunIdReturns400(): void
    {
        $res = $this->getJson('/api/v1/workflow/run/status');
        $this->assertSame(400, $res['status']);
    }

    /**
     * 需服务端已用共享 RunStore 启动，否则勿跑（会触发 Worker 本地 MEMORY 404 ERROR）。
     *
     * ```bash
     * WORKFLOW_RUN_STORE=redis php cli.php start Test
     * WORKFLOW_RUN_STORE=redis SWOOLEFY_HTTP_SHARED_RUN_STORE=1 composer test:http -- --filter testResume
     * ```
     */
    public function testResumeContractReviewCompletesWhenRunStoreShared(): void
    {
        if (!$this->httpSharedRunStoreEnabled()) {
            $this->markTestSkipped(
                'Cross-request status/resume needs shared RunStore. '
                . 'Start Test with WORKFLOW_RUN_STORE=redis|db and set SWOOLEFY_HTTP_SHARED_RUN_STORE=1.',
            );
        }

        $start = $this->postJson('/api/v1/workflow/run', [
            'workflowId' => 'contract_review',
            'input' => [
                'contractBrief' => 'Http resume golden path',
            ],
        ]);
        $this->assertSame(200, $start['status']);
        $runId = (string) ($this->responseData($start)['runId'] ?? '');
        $this->assertNotSame('', $runId);

        $headers = [
            'X-Workflow-Api-Key' => getenv('WORKFLOW_HITL_API_KEY') ?: 'test-hitl-key',
        ];

        $status = $this->getJson(
            '/api/v1/workflow/run/status?runId=' . rawurlencode($runId),
            $headers,
        );
        $this->assertSame(200, $status['status']);
        $this->assertSame('waiting', $this->responseData($status)['status'] ?? null);

        $resume = $this->postJson('/api/v1/workflow/run/resume', [
            'runId' => $runId,
            'feedback' => [
                'approved' => true,
                'comment' => 'LGTM',
            ],
        ], $headers);
        $this->assertSame(200, $resume['status']);
        $data = $this->responseData($resume);
        $this->assertSame($runId, $data['runId'] ?? null);
        $this->assertSame('completed', $data['status'] ?? null);
    }

    /**
     * 须显式声明：PHPUnit 进程里的 WORKFLOW_RUN_STORE 不等于服务端配置，
     * 误开会再次打到 MEMORY Worker 打出 ERROR 栈。
     */
    private function httpSharedRunStoreEnabled(): bool
    {
        return filter_var(getenv('SWOOLEFY_HTTP_SHARED_RUN_STORE') ?: '0', FILTER_VALIDATE_BOOLEAN);
    }
}
