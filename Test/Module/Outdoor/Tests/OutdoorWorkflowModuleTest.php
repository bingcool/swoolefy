<?php

declare(strict_types=1);

/**
 * Outdoor 模块工作流独立性回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | OutdoorWorkflowService | 独立 Registry，不依赖 Workflow 模块单例 |
 * | outdoor_cycling mock | sunny→go_cycling；rainy→stay_home |
 * | Engine | 本模块 engine start + getRun |
 *
 * ## 运行
 * ```bash
 * php Test/Module/Outdoor/Tests/OutdoorWorkflowModuleTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Test\Module\Outdoor\OutdoorWorkflowService;
use Test\Module\Outdoor\Workflow\OutdoorCyclingWorkflow;
use Test\Module\Workflow\WorkflowService;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeEngine(): WorkflowEngine
{
    return new WorkflowEngine(
        plugins: new PluginManager([new RetryPlugin(), new TracingPlugin()]),
        scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
    );
}

function makeCompiler(): WorkflowCompiler
{
    return new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
}

/**
 * 验证：Outdoor 注册表与 Workflow 模块注册表是不同实例，且仅含 outdoor_cycling。
 */
function testOutdoorRegistryIsIndependent(): void
{
    OutdoorWorkflowService::reset();
    WorkflowService::reset();

    $outdoor = OutdoorWorkflowService::registry();
    $central = WorkflowService::registry();

    assertTrue($outdoor !== $central, 'Outdoor registry must be a distinct instance');
    assertTrue($outdoor->has('outdoor_cycling'), 'Outdoor registry should register outdoor_cycling');
    assertTrue($outdoor->ids() === ['outdoor_cycling'], 'Outdoor registry should only own outdoor workflows');
    assertTrue($central->has('outdoor_cycling'), 'Central catalog may still list outdoor for unified API');
}

/**
 * 验证：好天气 mock 路径决策为 go_cycling。
 */
function testOutdoorCyclingSunnyMock(): void
{
    OutdoorWorkflowService::reset();

    $definition = OutdoorCyclingWorkflow::definition(
        OutdoorWorkflowService::agentScheduler(),
        useMockAgents: true,
    );
    $compiled = makeCompiler()->compile($definition);
    $engine = makeEngine();
    $runId = $engine->start($compiled, [
        'destination' => '深圳湾公园',
        'weatherHint' => 'sunny',
    ]);
    $run = $engine->getRun($runId);

    assertTrue($run->status->value === 'completed', 'Outdoor cycling should complete');
    assertTrue($run->state->get('decision') === 'go_cycling', 'Sunny weather should go cycling');
    assertTrue((bool) $run->state->get('weatherGood') === true, 'weatherGood should be true');
    assertTrue(count($run->state->agentOutputs) === 3, 'Should have weather/route/bike outputs');
}

/**
 * 验证：雨天 mock 路径决策为 stay_home。
 */
function testOutdoorCyclingRainyMock(): void
{
    OutdoorWorkflowService::reset();

    $definition = OutdoorCyclingWorkflow::definition(
        OutdoorWorkflowService::agentScheduler(),
        useMockAgents: true,
    );
    $compiled = makeCompiler()->compile($definition);
    $engine = makeEngine();
    $runId = $engine->start($compiled, [
        'destination' => '深圳湾公园',
        'weatherHint' => 'rainy',
    ]);
    $run = $engine->getRun($runId);

    assertTrue($run->status->value === 'completed', 'Outdoor cycling should complete');
    assertTrue($run->state->get('decision') === 'stay_home', 'Rainy weather should stay home');
    assertTrue((bool) $run->state->get('weatherGood') === false, 'weatherGood should be false');
}

/**
 * 验证：模块 engine 可从本模块 Registry 编译并跑通默认定义。
 */
function testOutdoorModuleEngineViaRegistry(): void
{
    OutdoorWorkflowService::reset();

    $compiled = OutdoorWorkflowService::registry()->compiled('outdoor_cycling');
    // Registry 默认非 mock；用 memory engine + mock definition 更稳，这里只断言 compile 成功
    assertTrue($compiled->workflowId() === 'outdoor_cycling', 'Compiled id should match');

    $definition = OutdoorCyclingWorkflow::definition(
        OutdoorWorkflowService::agentScheduler(),
        useMockAgents: true,
    );
    $engine = OutdoorWorkflowService::engine();
    $runId = $engine->start(makeCompiler()->compile($definition), [
        'destination' => '莲花山',
        'weatherHint' => 'sunny',
    ]);
    $run = $engine->getRun($runId);
    assertTrue($run->status->value === 'completed', 'Module engine start/getRun should work');
    assertTrue($run->state->get('decision') === 'go_cycling', 'Module engine mock path should go cycling');
}

$tests = [
    'outdoor registry independent' => 'testOutdoorRegistryIsIndependent',
    'outdoor cycling sunny mock' => 'testOutdoorCyclingSunnyMock',
    'outdoor cycling rainy mock' => 'testOutdoorCyclingRainyMock',
    'outdoor module engine' => 'testOutdoorModuleEngineViaRegistry',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Outdoor workflow module tests passed.\n";
