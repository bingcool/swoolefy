<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Research;

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use PhpUintTest\TestCase;
use Test\Module\Research\ResearchWorkflowService;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * Research 模块工作流独立性回归。
 */
final class ResearchWorkflowModuleTest extends TestCase
{
    public function testResearchRegistryIndependent(): void
    {
        ResearchWorkflowService::reset();
        WorkflowService::reset();

        $research = ResearchWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $research, 'Research registry must be distinct');
        $this->assertTrue($research->has('multi_agent_research'), 'has multi_agent_research');
        $this->assertTrue($research->has('mcp_research'), 'has mcp_research');
        $ids = $research->ids();
        sort($ids);
        $this->assertSame(['mcp_research', 'multi_agent_research'], $ids, 'only research workflows');
    }

    public function testMultiAgentMockViaModuleEngine(): void
    {
        ResearchWorkflowService::reset();

        $definition = MultiAgentResearchWorkflow::definition(
            ResearchWorkflowService::agentScheduler(),
            useMockAgents: true,
        );
        $compiled = (new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator()))->compile($definition);
        $engine = ResearchWorkflowService::engine();
        $runId = $engine->start($compiled, ['query' => 'Analyze swoolefy workflow design']);
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status, 'should complete');
        $this->assertCount(2, $run->state->agentOutputs, 'coding + finance outputs');
        $this->assertArrayHasKey('summary', $run->state->data, 'summary present');
    }

    public function testEngineForRunRoutesToResearch(): void
    {
        ResearchWorkflowService::reset();
        WorkflowService::reset();

        $definition = MultiAgentResearchWorkflow::definition(
            ResearchWorkflowService::agentScheduler(),
            useMockAgents: true,
        );
        $compiled = (new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator()))->compile($definition);
        $runId = ResearchWorkflowService::engine()->start($compiled, ['query' => 'route test']);

        $engine = WorkflowService::engineForRun($runId);
        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::COMPLETED, $run->status, 'federated engineForRun should find module run');
    }
}
