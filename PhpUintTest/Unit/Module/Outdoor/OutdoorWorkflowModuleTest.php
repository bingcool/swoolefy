<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Module\Outdoor;

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use PhpUintTest\TestCase;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Outdoor\Workflow\OutdoorCyclingWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * Outdoor 模块工作流独立性回归测试。
 */
final class OutdoorWorkflowModuleTest extends TestCase
{
    public function testOutdoorRegistryIsIndependent(): void
    {
        OutdoorWorkflowService::reset();
        WorkflowService::reset();

        $outdoor = OutdoorWorkflowService::registry();
        $central = WorkflowService::registry();

        $this->assertNotSame($central, $outdoor, 'Outdoor registry must be a distinct instance');
        $this->assertTrue($outdoor->has('outdoor_cycling'), 'Outdoor registry should register outdoor_cycling');
        $this->assertSame(['outdoor_cycling'], $outdoor->ids(), 'Outdoor registry should only own outdoor workflows');
        $this->assertTrue($central->has('outdoor_cycling'), 'Central catalog may still list outdoor for unified API');
    }

    public function testOutdoorCyclingSunnyMock(): void
    {
        OutdoorWorkflowService::reset();

        $definition = OutdoorCyclingWorkflow::definition(
            OutdoorWorkflowService::agentScheduler(),
            useMockAgents: true,
        );
        $compiled = $this->makeCompiler()->compile($definition);
        $engine = $this->makeEngine();
        $runId = $engine->start($compiled, [
            'destination' => '深圳湾公园',
            'weatherHint' => 'sunny',
        ]);
        $run = $engine->getRun($runId);

        $this->assertSame('completed', $run->status->value, 'Outdoor cycling should complete');
        $this->assertSame('go_cycling', $run->state->get('decision'), 'Sunny weather should go cycling');
        $this->assertTrue((bool) $run->state->get('weatherGood'), 'weatherGood should be true');
        $this->assertCount(3, $run->state->agentOutputs, 'Should have weather/route/bike outputs');
    }

    public function testOutdoorCyclingRainyMock(): void
    {
        OutdoorWorkflowService::reset();

        $definition = OutdoorCyclingWorkflow::definition(
            OutdoorWorkflowService::agentScheduler(),
            useMockAgents: true,
        );
        $compiled = $this->makeCompiler()->compile($definition);
        $engine = $this->makeEngine();
        $runId = $engine->start($compiled, [
            'destination' => '深圳湾公园',
            'weatherHint' => 'rainy',
        ]);
        $run = $engine->getRun($runId);

        $this->assertSame('completed', $run->status->value, 'Outdoor cycling should complete');
        $this->assertSame('stay_home', $run->state->get('decision'), 'Rainy weather should stay home');
        $this->assertFalse((bool) $run->state->get('weatherGood'), 'weatherGood should be false');
    }

    public function testOutdoorModuleEngineViaRegistry(): void
    {
        OutdoorWorkflowService::reset();

        $compiled = OutdoorWorkflowService::registry()->compiled('outdoor_cycling');
        $this->assertSame('outdoor_cycling', $compiled->workflowId(), 'Compiled id should match');

        $definition = OutdoorCyclingWorkflow::definition(
            OutdoorWorkflowService::agentScheduler(),
            useMockAgents: true,
        );
        $engine = OutdoorWorkflowService::engine();
        $runId = $engine->start($this->makeCompiler()->compile($definition), [
            'destination' => '莲花山',
            'weatherHint' => 'sunny',
        ]);
        $run = $engine->getRun($runId);
        $this->assertSame('completed', $run->status->value, 'Module engine start/getRun should work');
        $this->assertSame('go_cycling', $run->state->get('decision'), 'Module engine mock path should go cycling');
    }

    private function makeEngine(): WorkflowEngine
    {
        return new WorkflowEngine(
            plugins: new PluginManager([new RetryPlugin(), new TracingPlugin()]),
            scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        );
    }

    private function makeCompiler(): WorkflowCompiler
    {
        return new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
    }
}
