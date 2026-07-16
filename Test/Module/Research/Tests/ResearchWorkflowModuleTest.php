<?php

declare(strict_types=1);

/**
 * Research 模块工作流独立性回归。
 *
 * ```bash
 * php Test/Module/Research/Tests/ResearchWorkflowModuleTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Test\Module\Research\ResearchWorkflowService;
use Test\Module\Research\Workflow\MultiAgentResearchWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testResearchRegistryIndependent(): void
{
    ResearchWorkflowService::reset();
    WorkflowService::reset();

    $research = ResearchWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($research !== $central, 'Research registry must be distinct');
    assertTrue($research->has('multi_agent_research'), 'has multi_agent_research');
    assertTrue($research->has('mcp_research'), 'has mcp_research');
    $ids = $research->ids();
    sort($ids);
    assertTrue($ids === ['mcp_research', 'multi_agent_research'], 'only research workflows');
}

function testMultiAgentMockViaModuleEngine(): void
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

    assertTrue($run->status === RunStatus::COMPLETED, 'should complete');
    assertTrue(count($run->state->agentOutputs) === 2, 'coding + finance outputs');
    assertTrue(isset($run->state->data['summary']), 'summary present');
}

function testEngineForRunRoutesToResearch(): void
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
    assertTrue($run->status === RunStatus::COMPLETED, 'federated engineForRun should find module run');
}

$tests = [
    'research registry independent' => 'testResearchRegistryIndependent',
    'multi agent mock via module engine' => 'testMultiAgentMockViaModuleEngine',
    'engineForRun routes to research' => 'testEngineForRunRoutesToResearch',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Research workflow module tests passed.\n";
